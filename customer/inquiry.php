<?php
session_start();
include "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* 
|--------------------------------------------------------------------------
| FETCH LOGGED-IN USER DETAILS (FOR AUTO-FILL)
|--------------------------------------------------------------------------
*/
$user_id = (int)$_SESSION['user_id'];
$user_email  = '';
$user_phone  = '';
// Default address structured fields for pre-filling the order form
$def_province = '';
$def_city     = '';
$def_barangay = '';
$def_street   = '';

$user_stmt = $conn->prepare("SELECT email, phone FROM users WHERE id = ? LIMIT 1");
if ($user_stmt) {
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_result && $user_result->num_rows > 0) {
        $user_data   = $user_result->fetch_assoc();
        $user_email  = $user_data['email'] ?? '';
        $user_phone  = $user_data['phone'] ?? '';
    }
    $user_stmt->close();
}

// Fetch default address structured fields from user_addresses
$addr_stmt = $conn->prepare(
    "SELECT province, city, barangay, street_address
     FROM user_addresses WHERE user_id = ? AND is_default = 1 LIMIT 1"
);
if ($addr_stmt) {
    $addr_stmt->bind_param('i', $user_id);
    $addr_stmt->execute();
    $addr_row = $addr_stmt->get_result()->fetch_assoc();
    $addr_stmt->close();
    if ($addr_row) {
        $def_province = $addr_row['province']       ?? '';
        $def_city     = $addr_row['city']           ?? '';
        $def_barangay = $addr_row['barangay']       ?? '';
        $def_street   = $addr_row['street_address'] ?? '';
    }
}

// Also try to get the explicit default address from user_addresses (more reliable)
$addr_stmt = $conn->prepare(
    "SELECT address FROM user_addresses WHERE user_id = ? AND is_default = 1 LIMIT 1"
);
if ($addr_stmt) {
    $addr_stmt->bind_param('i', $user_id);
    $addr_stmt->execute();
    $addr_row = $addr_stmt->get_result()->fetch_assoc();
    $addr_stmt->close();
    if ($addr_row && !empty($addr_row['address'])) {
        $user_address = $addr_row['address'];
    }
}

/*
|--------------------------------------------------------------------------
| FIXED: SELF-HEALING CALENDAR SYNC (Matches availability_schedule.php)
|--------------------------------------------------------------------------
*/
$check_blocks = $conn->query("
    SELECT id, date 
    FROM availability_schedule 
    WHERE LOWER(status) != 'available'
      AND notes LIKE 'Auto-blocked:%'
");

if ($check_blocks && $check_blocks->num_rows > 0) {
    while ($block = $check_blocks->fetch_assoc()) {
        $blocked_date = $conn->real_escape_string($block['date']);
        $blocked_id = (int)$block['id'];
        
        // Count active direct services (exclude rows linked to inquiries & exclude 'Sold')
        $srv_res = $conn->query("
            SELECT COUNT(*) AS total 
            FROM services_schedule 
            WHERE service_date = '$blocked_date' 
              AND (inquiry_id IS NULL OR inquiry_id = 0)
              AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')
        ");
        $srv_count = $srv_res ? (int)$srv_res->fetch_assoc()['total'] : 0;
        
        // Count active inquiries (exclude 'Sold')
        $inq_res = $conn->query("
            SELECT COUNT(*) AS total 
            FROM inquiries 
            WHERE DATE(delivery_date) = '$blocked_date' 
              AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')
        ");
        $inq_count = $inq_res ? (int)$inq_res->fetch_assoc()['total'] : 0;
        
        // If combined active bookings drop below 3, remove the auto-block
        if (($srv_count + $inq_count) < 3) {
            $conn->query("DELETE FROM availability_schedule WHERE id = $blocked_id");
        }
    }
}

/*
|--------------------------------------------------------------------------
| GET PRODUCT INFORMATION (URL PARAMETERS)
|--------------------------------------------------------------------------
*/

$rawProduct = isset($_GET['product']) ? trim($_GET['product']) : '';
$rawBrand = isset($_GET['brand']) ? trim($_GET['brand']) : '';

// Capture quantity from either 'quantity' or 'qty' parameter
$quantityStr = (isset($_GET['quantity']) && trim($_GET['quantity']) !== '') ? trim($_GET['quantity']) : (isset($_GET['qty']) ? trim($_GET['qty']) : '1');

// If product_id was passed instead of the model name, fetch the model name from the database
if (empty($rawProduct) && isset($_GET['product_id'])) {
    $prod_id = (int)$_GET['product_id'];
    $id_query = $conn->query("SELECT IndoorUnitModel, brand FROM products WHERE id = $prod_id LIMIT 1");
    if ($id_query && $id_query->num_rows > 0) {
        $id_data = $id_query->fetch_assoc();
        $rawProduct = $id_data['IndoorUnitModel'];
        $rawBrand = $id_data['brand'];
    }
}

/*
|--------------------------------------------------------------------------
| MULTIPLE PRODUCTS SETUP
|--------------------------------------------------------------------------
*/

$modelList = !empty($rawProduct) ? array_map('trim', explode(',', $rawProduct)) : ['FP-51ARA006HMNV-N5'];
$brandList = !empty($rawBrand) ? array_map('trim', explode(',', $rawBrand)) : [$rawBrand ?: 'Midea'];
$quantityList = array_map('trim', explode(',', $quantityStr));

/*
|--------------------------------------------------------------------------
| SECURE TOTAL CALCULATION 
|--------------------------------------------------------------------------
*/
$calculatedTotalPrice = 0;

/*
|--------------------------------------------------------------------------
| FUNCTION: FIND PRODUCT IMAGE
|--------------------------------------------------------------------------
*/

function getInquiryProductImage($image, $brand, $model)
{
    $image = trim($image);
    $brand = strtolower(trim($brand));
    $model = trim($model);

    if ($image === '') return '../images/no-image.png';

    $image = basename($image);

    $gsbProductPics = [
        "FP-51ARA006HMNV-N5", "MWF1-07CMNS-NCO", "MWF1-09CMNS-NCO", "MWF2-12CMN8-NB9", 
        "MWTF2-18CMN1-NCO", "MWTF1-21CMN1-NCO", "MWF1-09CRN8-NCO", "MWF2-12CRN8-NB9", 
        "MWF2-18CRN8-NC1", "FP-51ARA010HEIV-N4", "FP-51ARA015HEIV-N4"
    ];

    $possiblePaths = [];

    if (in_array($model, $gsbProductPics, true)) {
        $possiblePaths[] = [
            'filesystem' => __DIR__ . '/../images/products/Gsb_productpics/' . $image,
            'url' => '../images/products/Gsb_productpics/' . rawurlencode($image)
        ];
    }
    if ($brand === 'midea') {
        $possiblePaths[] = [
            'filesystem' => __DIR__ . '/../images/products/midea/' . $image,
            'url' => '../images/products/midea/' . rawurlencode($image)
        ];
    }
    if ($brand === 'daikin') {
        $possiblePaths[] = [
            'filesystem' => __DIR__ . '/../images/products/daikin/' . $image,
            'url' => '../images/products/daikin/' . rawurlencode($image)
        ];
    }
    if ($brand === 'carrier') {
        $possiblePaths[] = [
            'filesystem' => __DIR__ . '/../images/products/carrier/' . $image,
            'url' => '../images/products/carrier/' . rawurlencode($image)
        ];
    }

    // Default admin upload directory
    $possiblePaths[] = [
        'filesystem' => __DIR__ . '/../images/products/' . $image,
        'url' => '../images/products/' . rawurlencode($image)
    ];

    foreach ($possiblePaths as $path) {
        if (file_exists($path['filesystem'])) return $path['url'];
    }

    return '../images/no-image.png';
}

/*
|--------------------------------------------------------------------------
| BUILD CAROUSEL PRODUCT DATA & COMPUTE PRICES
|--------------------------------------------------------------------------
*/

$carouselItems = [];
$unitPrices = []; // Track unit prices for frontend JS computation
$maxStocks = [];  // Track maximum stock for frontend JS limitation

foreach ($modelList as $index => $model) {
    $fallbackBrand = isset($brandList[$index]) ? $brandList[$index] : ($brandList[0] ?? 'Midea');
    
    // Determine exact quantity for this specific item in the loop
    $qty = isset($quantityList[$index]) ? (int)$quantityList[$index] : 1;

    $item = [
        'series' => 'Window Series',
        'brand' => $fallbackBrand,
        'horsepower' => '0.6 HP',
        'type' => 'Non-Inverter',
        'cooling_capacity' => 'Not yet provided',
        'warranty' => 'Not yet provided',
        'features' => 'Not yet provided',
        'durability' => 'Not yet provided',
        'description' => 'Not yet provided',
        'model' => $model,
        'image' => '',
        'display_image' => '../images/no-image.png',
        'base_price' => 0,
        'unit_price' => 0,
        'is_discounted' => false,
        'stock_status' => 'Available' // Default Status
    ];

    if (!empty($model)) {
        // FIXED QUERY: Added 'stock_status' to fetch dynamic availability
        $stmt = $conn->prepare("
            SELECT
                IndoorUnitModel, SeriesName, brand, CapacityHP, is_non_inverter, stock_quantity, stock_status,
                image, description, features, cooling_capacity, durability, warranty,
                SRP_PHP, is_promo, discount_percent, promo_end_date
            FROM products
            WHERE IndoorUnitModel = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("s", $model);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $dbRow = $res->fetch_assoc();

                // -------------------------------------------------------------
                // APPLY SECURE DISCOUNT CALCULATION & SAVE PRICING FOR CAROUSEL
                // -------------------------------------------------------------
                $basePrice = (float)$dbRow['SRP_PHP'];
                $unitPrice = $basePrice;
                $isDiscounted = false;

                if (isset($dbRow['is_promo']) && (int)$dbRow['is_promo'] === 1 && (int)$dbRow['discount_percent'] > 0) {
                    $current_time = date('Y-m-d H:i:s');
                    // Check if promo hasn't expired yet
                    if (empty($dbRow['promo_end_date']) || $dbRow['promo_end_date'] >= $current_time) {
                        $discount_amount = $basePrice * ((int)$dbRow['discount_percent'] / 100);
                        $unitPrice = $basePrice - $discount_amount;
                        $isDiscounted = true;
                    }
                }

                $item['base_price'] = $basePrice;
                $item['unit_price'] = $unitPrice;
                $item['is_discounted'] = $isDiscounted;

                // Save price and stock for real-time frontend JS changes
                $unitPrices[] = $unitPrice;
                $maxStocks[] = (int)($dbRow['stock_quantity'] ?? 0);

                // Add to the backend total based on the computed secure unit price
                $calculatedTotalPrice += ($unitPrice * $qty);
                // -------------------------------------------------------------

                if (!empty($dbRow['SeriesName'])) $item['series'] = $dbRow['SeriesName'];
                if (!empty($dbRow['brand'])) $item['brand'] = $dbRow['brand'];
                if (isset($dbRow['CapacityHP']) && $dbRow['CapacityHP'] !== '') $item['horsepower'] = $dbRow['CapacityHP'] . ' HP';
                if ($dbRow['is_non_inverter'] !== null) $item['type'] = ($dbRow['is_non_inverter'] == 1) ? 'Non-Inverter' : 'Inverter';
                if (!empty($dbRow['cooling_capacity'])) $item['cooling_capacity'] = $dbRow['cooling_capacity'];
                if (!empty($dbRow['warranty'])) $item['warranty'] = $dbRow['warranty'];
                if (!empty($dbRow['features'])) $item['features'] = $dbRow['features'];
                if (!empty($dbRow['durability'])) $item['durability'] = $dbRow['durability'];
                if (!empty($dbRow['description'])) $item['description'] = $dbRow['description'];
                if (!empty($dbRow['stock_status'])) $item['stock_status'] = $dbRow['stock_status']; // SET DYNAMIC STATUS

                if (!empty($dbRow['image'])) {
                    $item['image'] = $dbRow['image'];
                    $item['display_image'] = getInquiryProductImage($dbRow['image'], $item['brand'], $model);
                }
            }
            $stmt->close();
        }
    }
    $carouselItems[] = $item;
}

/*
|--------------------------------------------------------------------------
| ACTIVE PRODUCT
|--------------------------------------------------------------------------
*/

$activeItem = $carouselItems[0] ?? null;
$displayImage = $activeItem ? $activeItem['display_image'] : '../images/no-image.png';

/*
|--------------------------------------------------------------------------
| FIXED: UNAVAILABLE SCHEDULES & 3-BOOKING LIMIT CHECK (Matches Admin)
|--------------------------------------------------------------------------
*/

$unavailableSlots = [];

// 1. Count active Direct Services and Product Inquiries first
$dailyBookings = [];

// Get active direct Services count (exclude linked inquiry_id rows & 'Sold')
$servRes = $conn->query("
    SELECT service_date AS b_date, COUNT(*) AS total 
    FROM services_schedule 
    WHERE (inquiry_id IS NULL OR inquiry_id = 0)
      AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted') 
    GROUP BY service_date
");
if ($servRes) {
    while ($row = $servRes->fetch_assoc()) {
        if (!empty($row['b_date'])) {
            $dailyBookings[$row['b_date']] = (int)$row['total'];
        }
    }
}

// Get active Inquiries count (exclude 'Sold')
$inqRes = $conn->query("
    SELECT DATE(delivery_date) AS b_date, COUNT(*) AS total 
    FROM inquiries 
    WHERE delivery_date IS NOT NULL 
      AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted') 
    GROUP BY DATE(delivery_date)
");
if ($inqRes) {
    while ($row = $inqRes->fetch_assoc()) {
        $d = $row['b_date'];
        if (!empty($d)) {
            $dailyBookings[$d] = ($dailyBookings[$d] ?? 0) + (int)$row['total'];
        }
    }
}

// 2. Fetch manual & valid auto-inserted blocks from availability_schedule
$query = "SELECT * FROM availability_schedule WHERE LOWER(status) = 'unavailable' ORDER BY date, start_time";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $slotDate = $row['date'];
        $isAutoBlock = strpos($row['notes'] ?? '', 'Auto-blocked:') === 0;

        // Ignore stale auto-blocks if actual active bookings on that date are under 3
        if ($isAutoBlock && ($dailyBookings[$slotDate] ?? 0) < 3) {
            continue;
        }

        $startLabel = date("h:i A", strtotime($row['start_time']));
        $endLabel = date("h:i A", strtotime($row['end_time']));

        $unavailableSlots[] = [
            "dateOnly" => $slotDate,
            "timeLabel" => $isAutoBlock ? "Maximum daily limit reached (3/3)" : ($startLabel . " - " . $endLabel)
        ];
    }
}

// 3. Push any dates that reached 3/3 active bookings into unavailableSlots
foreach ($dailyBookings as $date => $total) {
    if ($total >= 3) {
        $unavailableSlots[] = [
            "dateOnly" => $date,
            "timeLabel" => "Maximum daily limit reached (3/3)"
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Inquiry & Order Review</title>
    <link rel="icon" type="image/png" href="favicon.png">

    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/inquiry.css">
    <link rel="stylesheet" href="css/footer.css">
    
    <!-- FONT AWESOME FOR ICONS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@3.2.0/dist/fullcalendar.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment@2.22.2/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@3.2.0/dist/fullcalendar.min.js"></script>
</head>
<body>

    <?php include 'navbar.php'; ?>

    <div class="inquiry-container">
        
        <!-- BACK BUTTON ADDED HERE -->
        <div class="back-btn-wrapper">
            <a href="javascript:history.back()" class="back-btn">&larr; Back</a>
        </div>

        <div class="inquiry-split-layout">

            <!-- =====================================================
                 LEFT SIDE
            ====================================================== -->
            
            <aside class="order-summary-sidebar">
                <div class="review-card">

                    <!-- CAROUSEL NAVIGATION -->
                    <div id="carouselNavControls" class="carousel-nav-bar" style="display: none;">
                        <button type="button" id="carouselPrevBtn" class="carousel-arrow-btn">&lt; Prev</button>
                        <span id="carouselCounter" class="carousel-counter-text">1 / 1</span>
                        <button type="button" id="carouselNextBtn" class="carousel-arrow-btn">Next &gt;</button>
                    </div>

                    <!-- BRAND / AVAILABILITY (FIXED TO DYNAMIC) -->
                    <div class="sidebar-top-badge" style="margin-top: 10px;">
                        <span class="brand-pill" id="carouselBrand">
                            <?php echo htmlspecialchars($activeItem['brand'] ?? 'Midea', ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        
                        <?php 
                            $statusText = $activeItem['stock_status'] ?? 'Available';
                            $statusColor = ($statusText === 'Limited Stock') ? '#d97706' : (($statusText === 'Out of Stock') ? '#dc2626' : '#166534');
                            $statusBg = ($statusText === 'Limited Stock') ? '#fef3c7' : (($statusText === 'Out of Stock') ? '#fee2e2' : '#dcfce7');
                        ?>
                        <span class="availability-pill" id="carouselAvailability" style="color: <?php echo $statusColor; ?>; background-color: <?php echo $statusBg; ?>;">
                            <?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>

                    <!-- PRODUCT IMAGE -->
                    <div class="product-preview-img-container">
                        <img id="productPreviewImg" 
                             src="<?php echo htmlspecialchars($displayImage, ENT_QUOTES, 'UTF-8'); ?>"
                             alt="<?php echo htmlspecialchars($activeItem['model'] ?? 'Product', ENT_QUOTES, 'UTF-8'); ?>"
                             onerror="this.onerror=null; this.src='../images/no-image.png';">
                    </div>

                    <!-- PRODUCT IDENTITY -->
                    <div class="product-identity-block">
                        <h3 class="product-series-title" id="productSeriesTitle">
                            <?php echo htmlspecialchars($activeItem['series'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?>
                        </h3>
                        <p class="product-model-code" id="productModelCode">
                            <?php echo htmlspecialchars($activeItem['model'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?>
                        </p>

                        <!-- DISPLAY ITEM PRICE -->
                        <div class="product-display-price" id="carouselPriceContainer" style="display: flex; flex-direction: column; align-items: flex-start; gap: 4px; margin-top: 10px;">
                            <span id="carouselOriginalPrice" style="text-decoration: line-through; color: #94a3b8; font-size: 0.85em; display: <?php echo ($activeItem['is_discounted'] ?? false) ? 'block' : 'none'; ?>;">
                                ₱<?php echo number_format($activeItem['base_price'] ?? 0, 2); ?>
                            </span>
                            <span id="carouselCurrentPrice" style="font-weight: 800; font-size: 1.25em; color: <?php echo ($activeItem['is_discounted'] ?? false) ? '#dc2626' : '#1e293b'; ?>;">
                                ₱<?php echo number_format($activeItem['unit_price'] ?? 0, 2); ?>
                            </span>
                        </div>
                    </div>

                    <!-- MINI SPECS -->
                    <div class="specs-mini-grid">
                        <div class="spec-tile">
                            <span class="spec-label">Horse Power</span>
                            <span class="spec-value" id="specHP"></span>
                        </div>
                        <div class="spec-tile">
                            <span class="spec-label">Type</span>
                            <span class="spec-value" id="specType"></span>
                        </div>
                        <div class="spec-tile">
                            <span class="spec-label">Cooling Capacity</span>
                            <span class="spec-value" id="specCoolingCapacity"></span>
                        </div>
                        <div class="spec-tile">
                            <span class="spec-label">Warranty</span>
                            <span class="spec-value" id="specWarranty"></span>
                        </div>
                    </div>

                    <!-- STATIC INSTALLATION BOX -->
                    <div class="spec-long-block" style="margin-top: 0; margin-bottom: 14px;">
                        <span class="spec-label">Installation</span>
                        <p class="spec-value-text">Free Installation Included</p>
                    </div>

                    <!-- FEATURES, DURABILITY, DESC -->
                    <div class="spec-long-block">
                        <span class="spec-label">Specs</span>
                        <div id="specFeaturesText"></div>
                    </div>
                    <div class="spec-long-block">
                        <span class="spec-label">Features</span>
                        <div id="specDurabilityText"></div>
                    </div>
                    <div class="spec-long-block">
                        <span class="spec-label">What's in the box</span>
                        <div id="specDescriptionText"></div>
                    </div>
                </div>
            </aside>


            <!-- =====================================================
                 RIGHT SIDE FORM
            ====================================================== -->
            <main class="form-main-column">
                <form action="save_inquiry.php" method="POST" id="inquiryForm">
                    <input type="hidden" name="address" id="fullAddressHidden">

                    <div class="form-card">
                        <div class="form-header">
                            <h2>Fill in Your Details</h2>
                            <p>Verify your selected unit(s) on the left and complete details below to lock in your Order.</p>
                        </div>

                        <div class="form-grid">
                            <!-- HIDDEN PRODUCT INFORMATION -->
                            <input type="hidden" name="product" value="<?php echo htmlspecialchars($rawProduct, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="brand" value="<?php echo htmlspecialchars($rawBrand, ENT_QUOTES, 'UTF-8'); ?>">

                            <!-- EDITABLE QUANTITY -->
                            <div class="form-group">
                                <label>Quantity</label>
                                <?php if (count($modelList) === 1): ?>
                                    <input type="number" id="quantity" name="quantity" 
                                           value="<?php echo (int)($quantityList[0] ?? 1); ?>" 
                                           min="1" max="<?php echo (int)($maxStocks[0] ?? 10); ?>" 
                                           oninput="updateTotalAmount()" style="width: 100%;">
                                <?php else: ?>
                                    <input type="text" value="Multiple Items (Edit in Cart)" readonly 
                                           style="background-color: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; cursor: not-allowed; font-weight: 500;">
                                    <input type="hidden" id="quantity" name="quantity" 
                                           value="<?php echo htmlspecialchars($quantityStr, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php endif; ?>
                            </div>

                            <!-- DISPLAY DB COMPUTED TOTAL PRICE FOR THE ENTIRE ORDER -->
                            <div class="form-group">
                                <label>Total Amount Computed</label>
                                <input type="text" id="totalAmountDisplay" value="₱<?php echo number_format($calculatedTotalPrice, 2); ?>" readonly>
                            </div>

                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" id="email" name="email" pattern="^[a-zA-Z0-9._%+-]+@gmail\.com$" title="Please provide a valid @gmail.com address" placeholder="example@gmail.com" value="<?php echo htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label>Phone Number (11 digits, 09XXXXXXXXX)</label>
                                <input type="tel" id="phone" name="phone" maxlength="11" inputmode="numeric" placeholder="09XXXXXXXXX" value="<?php echo htmlspecialchars($user_phone, ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <!-- DATE WITH CLICKABLE INFO ICON -->
                            <div class="form-group full">
                                <label style="display: flex; align-items: center; gap: 8px;">
                                    Preferred Date
                                    <span onclick="toggleDateInfo(event)" style="cursor: pointer; color: #0f1f78; font-size: 15px;" title="Click for info">
                                        <i class="fa-solid fa-circle-exclamation info-icon-btn"></i>
                                    </span>
                                </label>
                                
                                <!-- Toggleable Info Box -->
                                <div id="dateInfoBox" style="display: none; background: #eef2ff; border: 1px solid #c7d2fe; color: #3730a3; padding: 10px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 500; margin-bottom: 12px; line-height: 1.5;">
                                    <strong>Note on 3-Day Processing:</strong> This preferred date is tentative. You can still adjust or finalize the exact schedule during your confirmation conversation with our admin.
                                </div>

                                <button type="button" class="calendar-btn" id="openCalendarBtn">Select Date</button>
                                <input type="hidden" name="deliveryDate" id="deliveryDate" required>
                            </div>

                            <!-- ADDRESS — pre-filled from default saved address in Edit Profile -->
                            <?php if (!empty($def_province)): ?>
                            <div class="form-group full" style="margin-bottom:8px;">
                                <div style="display:flex;align-items:center;gap:8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;font-size:12.5px;color:#1d4ed8;font-weight:600;">
                                    <i class="fa-solid fa-check-circle"></i>
                                    Address pre-filled from your default saved address.
                                    <button type="button" onclick="openAddressPicker()" style="margin-left:auto;color:#1d4ed8;font-weight:700;background:none;border:none;cursor:pointer;font-size:12.5px;padding:0;font-family:inherit;">Change →</button>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="form-group full" style="margin-bottom:8px;">
                                <div style="display:flex;align-items:center;gap:8px;background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;font-size:12.5px;color:#92400e;font-weight:600;">
                                    <i class="fa-solid fa-location-dot"></i>
                                    No saved address found.
                                    <button type="button" onclick="openAddressPicker()" style="margin-left:auto;color:#1d4ed8;font-weight:700;background:none;border:none;cursor:pointer;font-size:12.5px;padding:0;font-family:inherit;">Manage Addresses →</button>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label for="province">Province / Region</label>
                                <select id="province" required>
                                    <option value="" disabled <?php echo empty($def_province) ? 'selected' : ''; ?>>Select Province/Region</option>
                                    <option value="NCR"  data-name="Metro Manila (NCR)" data-code="130000000" data-type="regions"   <?php echo $def_province==='Metro Manila (NCR)'?'selected':''; ?>>Metro Manila (NCR)</option>
                                    <option value="0410" data-name="Batangas"           data-code="041000000" data-type="provinces" <?php echo $def_province==='Batangas'?'selected':''; ?>>Batangas</option>
                                    <option value="0421" data-name="Cavite"             data-code="042100000" data-type="provinces" <?php echo $def_province==='Cavite'?'selected':''; ?>>Cavite</option>
                                    <option value="0434" data-name="Laguna"             data-code="043400000" data-type="provinces" <?php echo $def_province==='Laguna'?'selected':''; ?>>Laguna</option>
                                    <option value="0456" data-name="Quezon"             data-code="045600000" data-type="provinces" <?php echo $def_province==='Quezon'?'selected':''; ?>>Quezon</option>
                                    <option value="0458" data-name="Rizal"              data-code="045800000" data-type="provinces" <?php echo $def_province==='Rizal'?'selected':''; ?>>Rizal</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="city">City / Municipality</label>
                                <select id="city" <?php echo empty($def_city) ? 'disabled' : ''; ?> required>
                                    <option value="" disabled selected>Select City first</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="barangay">Barangay</label>
                                <select id="barangay" <?php echo empty($def_barangay) ? 'disabled' : ''; ?> required>
                                    <option value="" disabled selected>Select Barangay first</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Street Address</label>
                                <input type="text" id="streetAddress" name="street_address"
                                       value="<?php echo htmlspecialchars($def_street, ENT_QUOTES, 'UTF-8'); ?>"
                                       placeholder="House no., Street name," required>
                            </div>

                            <?php if (!empty($def_province)): ?>
                            <!-- Inject saved values for JS auto-cascade -->
                            <script>
                            var _prefillCity     = <?php echo json_encode($def_city); ?>;
                            var _prefillBarangay = <?php echo json_encode($def_barangay); ?>;
                            </script>
                            <?php endif; ?>

                            <!-- FREE INSTALLATION OPTION -->
                            <div class="form-group full">
                                <label style="margin-bottom: 10px; display: block; font-weight: bold; color: #0f172a;">Would you like to avail the FREE Installation?</label>
                                <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: center; background: #f8fafc; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                                    <label style="font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 8px; color: #1e293b;">
                                        <input type="radio" name="free_installation" value="Yes" required style="width: 18px; height: 18px; cursor: pointer;">
                                        Yes, I want free installation
                                    </label>
                                    <label style="font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 8px; color: #1e293b;">
                                        <input type="radio" name="free_installation" value="No" required style="width: 18px; height: 18px; cursor: pointer;">
                                        No, delivery only
                                    </label>
                                </div>
                            </div>

                            <!-- MODE OF PAYMENT (UNSELECTED BY DEFAULT) -->
                            <div class="form-group full">
                                <label style="margin-bottom: 10px; display: block; font-weight: bold; color: #0f172a;">Mode of Payment <span class="req">*</span></label>
                                <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: center; background: #f8fafc; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                                    <label style="font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 8px; color: #1e293b;" title="Payment is strictly Cash on Delivery at this time.">
                                        <input type="radio" name="payment_mode" value="COD" required style="width: 18px; height: 18px; cursor: pointer;">
                                        Cash on Delivery (COD)
                                    </label>
                                </div>
                            </div>

                            <!-- NOTES WITH MAXLENGTH -->
                            <div class="form-group full">
                                <label>Additional Notes or Concerns</label>
                                <textarea name="message" rows="4" maxlength="200" placeholder="E.g., Brand preferences, specific electrical outlet issues, or landmarks..."></textarea>
                                <small style="color: #64748b; font-size: 12px; display: block; margin-top: 5px;">Maximum 200 characters.</small>
                            </div>

                            <!-- BRANDED TERMS AGREEMENT -->
                            <div class="form-group full" style="margin-bottom: 0;">
                                <div class="terms-alert" id="termsAlertBox">
                                    <p>
                                        <i class="fa-solid fa-circle-exclamation" id="termsWarningIcon"></i>
                                        <span id="termsStatusText">Please read and check the </span>
                                        <a href="javascript:void(0)" onclick="openTermsModal()">GSB Terms of Use and Sale</a>
                                        <span id="termsStatusSuffix"> before placing your order.</span>
                                    </p>
                                    <input type="hidden" id="termsAcceptedInput" name="terms_accepted" value="0">
                                </div>
                                <button type="submit" class="submit-btn btn-disabled" id="placeOrderBtn" disabled>Place order</button>
                            </div>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <!-- =====================================================
          TERMS & AGREEMENT SCROLLABLE MODAL
    ====================================================== -->
    <div class="modal" id="termsModal" style="z-index: 10001; background: rgba(0,0,0,0.7); display: none; align-items: center; justify-content: center;">
        <div class="modal-card" style="max-width: 550px; width: 95%; padding: 0; overflow: hidden; display: flex; flex-direction: column; max-height: 85vh; border-radius: 12px; background: white;">
            
            <!-- Branded Header -->
            <div class="terms-modal-header" style="padding: 20px 25px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 18px;">GSB Terms of Use & Sale</h3>
                <button type="button" onclick="closeTermsAndGoBack()" style="background: none; border: none; font-size: 26px; cursor: pointer; transition: 0.2s; color: #64748b; line-height: 1;" title="Close and go back">&times;</button>
            </div>
            
            <!-- Scrollable Content -->
            <div style="padding: 25px; overflow-y: auto; background: #f8fafc; font-size: 14px; color: #475569; line-height: 1.6; text-align: left; flex-grow: 1;">
                <h4 style="color: #1e293b; margin-top: 0; font-size: 15px;">1. Strict Cash on Delivery (COD) Policy</h4>
                <p style="margin-bottom: 20px;">All orders placed through the GSB Airconditioning platform are strictly payable via Cash on Delivery (COD). No advance payments are required unless specifically advised and verified by official GSB Management.</p>

                <h4 style="color: #1e293b; font-size: 15px;">2. Cancellation Policy & Fees</h4>
                <p style="margin-bottom: 20px;">We allocate technicians and dispatch logistics for your preferred schedule. Cancellations must be made at least <strong>24 hours</strong> prior to your scheduled delivery date. <strong>Late cancellations or refusal to accept the delivery upon arrival will incur a Cancellation Fee of ₱500.00</strong> to compensate for dispatching and logistics expenses.</p>

                <h4 style="color: #1e293b; font-size: 15px;">3. Free Installation Guidelines</h4>
                <p style="margin-bottom: 20px;">The "Free Installation" promo covers standard labor and basic materials (e.g., standard copper tubing length). Excess tubing, specialized brackets, heavy civil works, or electrical upgrades are NOT included and will be billed separately by the technician on site.</p>
                
                <h4 style="color: #1e293b; font-size: 15px;">4. Privacy Policy</h4>
                <p style="margin-bottom: 0;">By using our service, you acknowledge that your personal information will be securely used exclusively for order fulfillment, dispatching, and customer support.</p>
            </div>

            <!-- Bottom Checkbox and Accept Button -->
            <div style="padding: 20px 25px; border-top: 1px solid #e2e8f0; background: white;">
                <label style="display: flex; gap: 12px; align-items: flex-start; cursor: pointer; margin-bottom: 20px; padding: 10px; background: #f1f5f9; border-radius: 8px;">
                    <input type="checkbox" id="modalAgreeCheckbox" style="width: 20px; height: 20px; margin-top: 2px; cursor: pointer;" onchange="toggleTermsAcceptButton()">
                    <span style="font-size: 14px; color: #0f172a; font-weight: 500;">I have read, understood, and agree to the GSB Terms of Use and Sale.</span>
                </label>
                <button type="button" id="modalAcceptBtn" onclick="confirmTerms()" class="submit-btn btn-disabled" disabled>Accept & Continue</button>
            </div>
            
        </div>
    </div>

    <!-- =====================================================
          ORDER CONFIRMATION / LINE OPEN MODAL (NEW)
    ====================================================== -->
    <div id="orderConfirmationModal" class="modal" style="z-index: 10000; align-items: center; justify-content: center; background: rgba(0,0,0,0.6);">
        <div class="modal-content" style="max-width: 400px; text-align: center; padding: 35px 20px; border-radius: 8px;">
            <i class="fa-solid fa-phone-volume" style="font-size: 40px; color: #0f1f78; margin-bottom: 15px;"></i>
            <h3 style="margin-top: 0; color: #1e293b; margin-bottom: 15px; font-size: 1.35rem;">Order Ready!</h3>
            <p style="color: #475569; margin-bottom: 25px; line-height: 1.6; font-size: 0.95rem;">
                Please keep your line open, our team will reach out to you via phone call as soon as possible. Thank you!
            </p>
            <div style="display: flex; gap: 10px;">
                <button type="button" onclick="document.getElementById('orderConfirmationModal').style.display='none';" style="background: white; color: #475569; border: 1px solid #cbd5e1; padding: 12px; border-radius: 5px; cursor: pointer; font-weight: 600; width: 40%;">Cancel</button>
                <button type="button" id="finalSubmitBtn" style="background: #0f1f78; color: white; border: none; padding: 12px; border-radius: 5px; cursor: pointer; font-weight: 600; width: 60%;">OK, Submit</button>
            </div>
        </div>
    </div>

    <!-- =====================================================
          CALENDAR MODAL
    ====================================================== -->
    <div id="calendarModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" id="closeModalBtn">&times;</span>
            <div id="calendar"></div>
            <div class="calendar-legend">
                <div class="calendar-legend-item"><div class="calendar-legend-dot legend-available"></div> Available</div>
                <div class="calendar-legend-item"><div class="calendar-legend-dot legend-booked"></div> Fully Booked</div>
                <div class="calendar-legend-item"><div class="calendar-legend-dot legend-today"></div> Today</div>
                <div class="calendar-legend-item"><div class="calendar-legend-dot legend-past"></div> Past Date</div>
            </div>
        </div>
    </div>

    <!-- =====================================================
          CUSTOM ALERT MODAL
    ====================================================== -->
    <div id="customAlertModal" class="modal" style="z-index: 10000; align-items: center; justify-content: center; background: rgba(0,0,0,0.6);">
        <div class="modal-content" style="max-width: 400px; text-align: center; padding: 30px 20px; border-radius: 8px;">
            <h3 style="margin-top: 0; color: #1e293b; margin-bottom: 15px; font-size: 1.25rem;">Notice</h3>
            <p id="customAlertMessage" style="color: #475569; margin-bottom: 25px; line-height: 1.5; white-space: pre-wrap; font-size: 0.95rem;"></p>
            <button type="button" id="closeCustomAlertBtn" style="background: #1e3a8a; color: white; border: none; padding: 10px 25px; border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 1rem; width: 100%;">OK</button>
        </div>
    </div>

    <!-- =========================================================
          FOOTER
    ========================================================= -->
    <?php include 'footer.php'; ?>

    <script>
        // ==========================================
        // TOGGLE DATE INFO MESSAGE
        // ==========================================
        function toggleDateInfo(e) {
            if (e) e.preventDefault(); 
            
            const infoBox = document.getElementById('dateInfoBox');
            if (infoBox.style.display === 'none' || infoBox.style.display === '') {
                infoBox.style.display = 'block';
            } else {
                infoBox.style.display = 'none';
            }
        }

        const isSingleProduct = <?php echo count($modelList) === 1 ? 'true' : 'false'; ?>;
        const singleUnitPrice = <?php echo count($modelList) === 1 ? (float)($unitPrices[0] ?? 0) : 0; ?>;
        const maxAvailableStock = <?php echo count($modelList) === 1 ? (int)($maxStocks[0] ?? 1) : 1; ?>;

        function updateTotalAmount() {
            if (!isSingleProduct) return;
            
            let qtyInput = document.getElementById('quantity');
            let qty = parseInt(qtyInput.value);
            
            if (isNaN(qty) || qty < 1) {
                qty = 1;
            }
            
            if (qty > maxAvailableStock) {
                showCustomAlert(`Sorry, we only have ${maxAvailableStock} piece(s) available in stock.`);
                qty = maxAvailableStock;
                qtyInput.value = maxAvailableStock;
            }
            
            let newTotal = qty * singleUnitPrice;
            document.getElementById('totalAmountDisplay').value = '₱' + newTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }


        function formatAsList(value, fallback = "Not yet provided") {
            if (!value) return `<p class="spec-value-text">${fallback}</p>`;
            let validLines = String(value).replaceAll("<", "&lt;").replaceAll(">", "&gt;").split(/\r?\n/).filter(line => line.trim() !== "");
            if (validLines.length === 0) return `<p class="spec-value-text">${fallback}</p>`;
            let html = "<ul class='spec-list'>";
            validLines.forEach(line => { html += `<li>${line.trim()}</li>`; });
            return html + "</ul>";
        }

        function showCustomAlert(message) {
            document.getElementById('customAlertMessage').textContent = message;
            document.getElementById('customAlertModal').style.display = 'flex';
        }
        document.getElementById('closeCustomAlertBtn').onclick = function () {
            document.getElementById('customAlertModal').style.display = 'none';
        };

        const carouselItems = <?php echo json_encode($carouselItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        let currentCarouselIndex = 0;

        function renderCarouselItem(idx) {
            if (!carouselItems || carouselItems.length === 0) return;
            const item = carouselItems[idx];

            document.getElementById('carouselBrand').textContent = item.brand || 'Midea';
            
            // DYNAMIC AVAILABILITY PILL
            const availPill = document.getElementById('carouselAvailability');
            const status = item.stock_status || 'Available';
            availPill.textContent = status;
            
            if (status === 'Limited Stock') {
                availPill.style.backgroundColor = '#fef3c7'; // yellow-100
                availPill.style.color = '#d97706'; // yellow-600
            } else if (status === 'Out of Stock') {
                availPill.style.backgroundColor = '#fee2e2'; // red-100
                availPill.style.color = '#dc2626'; // red-600
            } else {
                availPill.style.backgroundColor = '#dcfce7'; // green-100
                availPill.style.color = '#166534'; // green-800
            }
            
            const productImage = document.getElementById('productPreviewImg');
            productImage.onerror = function () {
                this.onerror = null;
                this.src = '../images/no-image.png';
            };
            productImage.src = item.display_image || '../images/no-image.png';
            productImage.alt = item.model || 'Product Image';

            document.getElementById('productSeriesTitle').textContent = item.series || 'Window Series';
            document.getElementById('productModelCode').textContent = item.model || '';
            
            const formatPrice = (num) => parseFloat(num).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            
            const origPriceEl = document.getElementById('carouselOriginalPrice');
            const currPriceEl = document.getElementById('carouselCurrentPrice');

            if (item.is_discounted) {
                origPriceEl.style.display = 'block';
                origPriceEl.textContent = '₱' + formatPrice(item.base_price);
                currPriceEl.style.color = '#dc2626'; // Red
                currPriceEl.textContent = '₱' + formatPrice(item.unit_price);
            } else {
                origPriceEl.style.display = 'none';
                currPriceEl.style.color = '#1e293b'; 
                currPriceEl.textContent = '₱' + formatPrice(item.unit_price);
            }

            // Update matching specs UI
            document.getElementById('specHP').textContent = item.horsepower || '0.6 HP';
            document.getElementById('specType').textContent = item.type || 'Non-Inverter';
            document.getElementById('specCoolingCapacity').textContent = item.cooling_capacity || 'Not yet provided';
            document.getElementById('specWarranty').textContent = item.warranty || 'Not yet provided';
            
            // Format long text as bullet lists to match products.php exactly
            document.getElementById('specFeaturesText').innerHTML = formatAsList(item.features, 'Not yet provided');
            document.getElementById('specDurabilityText').innerHTML = formatAsList(item.durability, 'Not yet provided');
            document.getElementById('specDescriptionText').innerHTML = formatAsList(item.description, 'Not yet provided');

            if (carouselItems.length > 1) {
                document.getElementById('carouselNavControls').style.display = 'flex';
                document.getElementById('carouselCounter').textContent = `${idx + 1} / ${carouselItems.length}`;
                document.getElementById('carouselPrevBtn').disabled = idx === 0;
                document.getElementById('carouselNextBtn').disabled = idx === carouselItems.length - 1;
            } else {
                document.getElementById('carouselNavControls').style.display = 'none';
            }
        }

        if (carouselItems.length > 0) {
            renderCarouselItem(0);
            document.getElementById('carouselPrevBtn').addEventListener('click', () => {
                if (currentCarouselIndex > 0) {
                    currentCarouselIndex--;
                    renderCarouselItem(currentCarouselIndex);
                }
            });
            document.getElementById('carouselNextBtn').addEventListener('click', () => {
                if (currentCarouselIndex < carouselItems.length - 1) {
                    currentCarouselIndex++;
                    renderCarouselItem(currentCarouselIndex);
                }
            });
        }

        const provinceSelect = document.getElementById('province');
        const citySelect = document.getElementById('city');
        const barangaySelect = document.getElementById('barangay');

        function populateCities(data) {
            citySelect.innerHTML = '<option value="" disabled selected>Select City / Municipality</option>';
            data.sort((a, b) => a.name.localeCompare(b.name));
            data.forEach(city => {
                let option = document.createElement('option');
                option.value = city.code;
                option.text = city.name;
                option.dataset.name = city.name;
                citySelect.appendChild(option);
            });
            citySelect.disabled = false;
        }

        provinceSelect.addEventListener('change', function() {
            citySelect.innerHTML = '<option value="" disabled selected>Loading Cities...</option>';
            barangaySelect.innerHTML = '<option value="" disabled selected>Select Barangay first</option>';
            citySelect.disabled = true;
            barangaySelect.disabled = true;

            const val = this.value; 
            if (val === 'NCR') {
                fetch(`https://psgc.gitlab.io/api/regions/130000000/cities-municipalities/`)
                    .then(res => res.json())
                    .then(data => populateCities(data))
                    .catch(err => console.error(err));
            } else {
                fetch(`https://psgc.gitlab.io/api/regions/040000000/cities-municipalities/`)
                    .then(res => res.json())
                    .then(data => {
                        const filteredCities = data.filter(city => city.code.startsWith(val));
                        populateCities(filteredCities);
                    })
                    .catch(err => console.error(err));
            }
        });

        citySelect.addEventListener('change', function() {
            barangaySelect.innerHTML = '<option value="" disabled selected>Loading Barangays...</option>';
            barangaySelect.disabled = true;

            const cityCode = this.value;
            fetch(`https://psgc.gitlab.io/api/cities-municipalities/${cityCode}/barangays/`)
                .then(res => res.json())
                .then(data => {
                    barangaySelect.innerHTML = '<option value="" disabled selected>Select Barangay</option>';
                    data.sort((a, b) => a.name.localeCompare(b.name));
                    data.forEach(brgy => {
                        let option = document.createElement('option');
                        option.value = brgy.code;
                        option.text = brgy.name;
                        option.dataset.name = brgy.name;
                        barangaySelect.appendChild(option);
                    });
                    barangaySelect.disabled = false;
                });
        });

        // ── Auto-cascade pre-filled province → city → barangay on page load ──
        if (typeof _prefillCity !== 'undefined' && _prefillCity && provinceSelect.value) {
            // Trigger province change to load cities, then select the saved city
            provinceSelect.dispatchEvent(new Event('change'));

            // After cities load, select the city and load barangays
            const _waitForCities = setInterval(function() {
                if (!citySelect.disabled && citySelect.options.length > 1) {
                    clearInterval(_waitForCities);
                    // Find and select the matching city by name
                    for (let i = 0; i < citySelect.options.length; i++) {
                        if (citySelect.options[i].text === _prefillCity) {
                            citySelect.selectedIndex = i;
                            citySelect.dispatchEvent(new Event('change'));
                            break;
                        }
                    }
                    // After barangays load, select the matching barangay
                    if (typeof _prefillBarangay !== 'undefined' && _prefillBarangay) {
                        const _waitForBarangays = setInterval(function() {
                            if (!barangaySelect.disabled && barangaySelect.options.length > 1) {
                                clearInterval(_waitForBarangays);
                                for (let i = 0; i < barangaySelect.options.length; i++) {
                                    if (barangaySelect.options[i].dataset.name === _prefillBarangay ||
                                        barangaySelect.options[i].text === _prefillBarangay) {
                                        barangaySelect.selectedIndex = i;
                                        break;
                                    }
                                }
                            }
                        }, 200);
                    }
                }
            }, 200);
        }

        const phoneInput = document.getElementById('phone');
        phoneInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 11);
        });

        document.getElementById('openCalendarBtn').onclick = function () {
            document.getElementById('calendarModal').style.display = "block";
            setTimeout(function () { $('#calendar').fullCalendar('render'); }, 100);
        };
        document.getElementById('closeModalBtn').onclick = function () {
            document.getElementById('calendarModal').style.display = "none";
        };

        $(document).ready(function () {
            var unavailableDates = <?php echo json_encode($unavailableSlots, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

            function isDateUnavailable(selectedDate) {
                for (var i = 0; i < unavailableDates.length; i++) {
                    if (selectedDate === unavailableDates[i].dateOnly) return unavailableDates[i];
                }
                return false;
            }

            $('#calendar').fullCalendar({
                header: { left: 'prev,next today', center: 'title', right: '' },
                defaultView: 'month',
                selectable: true,
                selectHelper: true,
                
                dayRender: function (date, cell) {
                    var todayString = moment().format('YYYY-MM-DD');
                    // ENFORCING 3-DAY MINIMUM LEAD TIME
                    var minAllowedDate = moment().add(3, 'days').format('YYYY-MM-DD');
                    var dateString = date.format('YYYY-MM-DD');

                    if (dateString < todayString) {
                        cell.addClass('fc-past-day');
                        $('.fc-day-top[data-date="' + dateString + '"]').addClass('fc-disabled-day-text'); 
                    } else if (dateString < minAllowedDate) {
                        if (dateString !== todayString) {
                            cell.addClass('fc-buffer-day');
                        }
                        cell.attr('title', 'Please select a date at least 3 days from today.');
                        $('.fc-day-top[data-date="' + dateString + '"]').addClass('fc-disabled-day-text');
                    } else {
                        var unavailableInfo = isDateUnavailable(dateString);
                        if (unavailableInfo) {
                            cell.addClass('fc-unavailable-day');
                            $('.fc-day-top[data-date="' + dateString + '"]').addClass('fc-disabled-day-text');
                            
                            var tooltip = 'Unavailable';
                            if (unavailableInfo.timeLabel) tooltip += '\nDetails: ' + unavailableInfo.timeLabel;
                            cell.attr('title', tooltip);
                        } else {
                            cell.addClass('fc-available-day');
                        }
                    }
                },
                dayClick: function (date) {
                    var minAllowedDate = moment().add(3, 'days').format('YYYY-MM-DD');
                    var selectedDate = date.format('YYYY-MM-DD');

                    if (selectedDate < minAllowedDate) {
                        showCustomAlert("Please select a date at least 3 days from today for processing and shipping.");
                        return false;
                    }

                    var unavailableInfo = isDateUnavailable(selectedDate);
                    if (unavailableInfo) {
                        var msg = "This date is unavailable.\n";
                        if (unavailableInfo.timeLabel) msg += "Details: " + unavailableInfo.timeLabel + "\n";
                        showCustomAlert(msg);
                        return false;
                    }

                    $('#deliveryDate').val(selectedDate);
                    $('#openCalendarBtn').text(selectedDate).css('color', '#1e293b');
                    document.getElementById('calendarModal').style.display = "none";
                },
                select: function (start) {
                    var minAllowedDate = moment().add(3, 'days').format('YYYY-MM-DD');
                    var selectedDate = start.format('YYYY-MM-DD');

                    if (selectedDate < minAllowedDate || isDateUnavailable(selectedDate)) {
                        $('#calendar').fullCalendar('unselect');
                        if (selectedDate < minAllowedDate) {
                            showCustomAlert("Please select a date at least 3 days from today.");
                        } else {
                            var unavailableInfo = isDateUnavailable(selectedDate);
                            var msg = "This date is unavailable.\n";
                            if (unavailableInfo && unavailableInfo.timeLabel) msg += "Details: " + unavailableInfo.timeLabel + "\n";
                            showCustomAlert(msg);
                        }
                        return false;
                    }

                    $('#deliveryDate').val(selectedDate);
                    $('#openCalendarBtn').text(selectedDate).css('color', '#1e293b');
                    document.getElementById('calendarModal').style.display = "none";
                }
            });
        });

        // ==========================================
        // TERMS AND AGREEMENT LOGIC
        // ==========================================
        function openTermsModal() {
            document.getElementById('termsModal').style.display = 'flex';
            document.body.style.overflow = 'hidden'; 
        }

        function closeTermsModal() {
            // Only allow closing if terms have been accepted
            if (document.getElementById('termsAcceptedInput').value === "1") {
                document.getElementById('termsModal').style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        // X button: close modal and go back to products.php (last visited page)
        function closeTermsAndGoBack() {
            document.getElementById('termsModal').style.display = 'none';
            document.body.style.overflow = 'auto';
            // Go back to products.php, preserving any search/filter state via history
            if (document.referrer && document.referrer.indexOf('products.php') !== -1) {
                window.location.href = document.referrer;
            } else {
                window.location.href = 'products.php';
            }
        }

        // ── Fire modal immediately on page load ──
        document.addEventListener('DOMContentLoaded', function() {
            // Lock all form inputs until terms accepted
            lockFormInputs(true);
            openTermsModal();
        });

        // ── Lock / unlock form inputs ──
        function lockFormInputs(lock) {
            const form = document.getElementById('inquiryForm');
            if (!form) return;
            const fields = form.querySelectorAll('input:not([type="hidden"]):not([type="submit"]), select, textarea');
            fields.forEach(function(el) {
                el.disabled = lock;
                el.style.opacity    = lock ? '0.45' : '1';
                el.style.background = lock ? '#f1f5f9' : '';
                el.style.cursor     = lock ? 'not-allowed' : '';
            });
        }

        function toggleTermsAcceptButton() {
            const isChecked = document.getElementById('modalAgreeCheckbox').checked;
            const btn = document.getElementById('modalAcceptBtn');
            if (isChecked) {
                btn.disabled = false;
                btn.classList.remove('btn-disabled');
                btn.classList.add('btn-enabled');
            } else {
                btn.disabled = true;
                btn.classList.remove('btn-enabled');
                btn.classList.add('btn-disabled');
            }
        }

        function confirmTerms() {
            document.getElementById('termsAcceptedInput').value = "1";
            const alertBox = document.getElementById('termsAlertBox');
            alertBox.classList.add('accepted');

            document.getElementById('termsWarningIcon').className = "fa-solid fa-circle-check";
            
            document.getElementById('termsStatusText').textContent = "By placing an order, you agree to the ";
            document.getElementById('termsStatusSuffix').textContent = " and Privacy Policy.";
            
            const placeOrderBtn = document.getElementById('placeOrderBtn');
            placeOrderBtn.disabled = false;
            placeOrderBtn.classList.remove('btn-disabled');
            placeOrderBtn.classList.add('btn-enabled');

            // ── Unlock all form inputs now that terms are accepted ──
            lockFormInputs(false);
            
            closeTermsModal();
        }

        // Clicking the backdrop no longer closes the modal (terms must be accepted first)
        window.addEventListener('click', function(event) {
            const termsModal = document.getElementById('termsModal');
            if (event.target == termsModal && document.getElementById('termsAcceptedInput').value === "1") {
                closeTermsModal();
            }
        });

        // ==========================================
        // FORM SUBMISSION VALIDATION & INTERCEPT
        // ==========================================
        document.getElementById('inquiryForm').addEventListener('submit', function (e) {
            
            if (document.getElementById('termsAcceptedInput').value !== "1") {
                showCustomAlert("Please read and accept the GSB Terms of Use and Sale by clicking the link above the Place Order button.");
                e.preventDefault();
                return;
            }

            if (isSingleProduct) {
                let finalQty = parseInt(document.getElementById('quantity').value) || 1;
                if (finalQty >= 5) {
                    e.preventDefault();
                    showCustomAlert("You have selected 5 or more items. You will be redirected to the Bulk Order form to complete your transaction.");
                    setTimeout(() => {
                        window.location.href = `bulk_order.php?product=<?php echo urlencode($rawProduct); ?>&brand=<?php echo urlencode($rawBrand); ?>&quantity=${finalQty}`;
                    }, 2500);
                    return;
                }
            }
            
            const reqIds = ['email', 'phone', 'province', 'city', 'barangay', 'streetAddress', 'deliveryDate'];
            for (let id of reqIds) {
                let el = document.getElementById(id) || document.getElementsByName(id)[0];
                if (!el || el.value === null || el.value.trim() === '') {
                    showCustomAlert("Please fill out all required fields.");
                    if (el && el.focus) el.focus();
                    e.preventDefault();
                    return;
                }
            }
            
            const freeInstall = document.querySelector('input[name="free_installation"]:checked');
            if (!freeInstall) {
                showCustomAlert("Please select if you want to avail the free installation.");
                e.preventDefault();
                return;
            }

            const paymentMode = document.querySelector('input[name="payment_mode"]:checked');
            if (!paymentMode) {
                showCustomAlert("Please select your Mode of Payment.");
                e.preventDefault();
                return;
            }

            const emailVal = document.getElementById('email').value.trim().toLowerCase();
            if (!emailVal.endsWith('@gmail.com')) {
                showCustomAlert("Only @gmail.com email addresses are allowed.");
                document.getElementById('email').focus();
                e.preventDefault();
                return;
            }

            const phoneVal = phoneInput.value.trim();
            if (!/^09\d{9}$/.test(phoneVal)) {
                showCustomAlert("Please enter a valid 11-digit Philippine mobile number starting with 09 (e.g., 09123456789).");
                phoneInput.focus();
                e.preventDefault();
                return;
            }

            const minAllowedDate = moment().add(3, 'days').format('YYYY-MM-DD');
            if (document.getElementById('deliveryDate').value < minAllowedDate) {
                showCustomAlert("Preferred date must be at least 3 days from today.");
                e.preventDefault();
                return;
            }

            try {
                const street = document.getElementById('streetAddress').value.trim();
                const brgy = barangaySelect.options[barangaySelect.selectedIndex].text;
                const city = citySelect.options[citySelect.selectedIndex].text;
                const prov = provinceSelect.options[provinceSelect.selectedIndex].dataset.name || provinceSelect.options[provinceSelect.selectedIndex].text;

                document.getElementById('fullAddressHidden').value = `${street}, Brgy. ${brgy}, ${city}, ${prov}`;
            } catch (error) {
                showCustomAlert("Please make sure your Province, City, and Barangay are fully selected.");
                e.preventDefault();
                return;
            }
            
            e.preventDefault();
            document.getElementById('orderConfirmationModal').style.display = 'flex';
        });
        
        document.getElementById('finalSubmitBtn').addEventListener('click', function() {
            document.getElementById('orderConfirmationModal').style.display = 'none';
            document.getElementById('inquiryForm').submit();
        });
    </script>

    <!-- ══════════════════════════════════════════════════════
         ADDRESS PICKER MODAL
    ═══════════════════════════════════════════════════════ -->
    <div id="addressPickerModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:99999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:16px;padding:28px 24px;width:min(520px,94vw);max-height:90vh;overflow-y:auto;position:relative;box-shadow:0 20px 60px rgba(0,0,0,0.25);">
            <button type="button" onclick="closeAddressPicker()" style="position:absolute;top:14px;right:16px;background:#f1f5f9;border:none;font-size:16px;color:#64748b;cursor:pointer;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;line-height:1;">✕</button>

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;padding-right:40px;">
                <h3 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
                    <i class="fa-solid fa-location-dot" style="color:#1d4ed8;"></i> Saved Addresses
                </h3>
                <button type="button" onclick="closeAddressPicker();window.location.href='edit_profile.php';" style="background:#1d4ed8;color:#fff;border:none;border-radius:8px;padding:7px 14px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;">
                    + Manage
                </button>
            </div>
            <p style="margin:0 0 16px;font-size:13px;color:#64748b;">Select an address to use for this order.</p>

            <div id="addressPickerList" style="display:flex;flex-direction:column;gap:10px;">
                <p style="color:#94a3b8;text-align:center;font-size:13px;">Loading addresses...</p>
            </div>
        </div>
    </div>

    <script>
    // Province code map — matches inquiry.php select values
    const _provinceMap = {
        'Metro Manila (NCR)': 'NCR',
        'Batangas': '0410',
        'Cavite':   '0421',
        'Laguna':   '0434',
        'Quezon':   '0456',
        'Rizal':    '0458',
    };

    function openAddressPicker() {
        document.getElementById('addressPickerModal').style.display = 'flex';
        // Load addresses via AJAX
        fetch('manage_addresses.php?action=list')
            .then(r => r.json())
            .then(data => {
                const list = document.getElementById('addressPickerList');
                if (!data.ok || !data.addresses || data.addresses.length === 0) {
                    list.innerHTML = '<p style="color:#94a3b8;text-align:center;font-size:13px;">No saved addresses. <a href="edit_profile.php" style="color:#1d4ed8;">Add one in Edit Profile</a>.</p>';
                    return;
                }
                list.innerHTML = data.addresses.map(a => `
                    <div onclick="selectAddress(${JSON.stringify(a).replace(/"/g,'&quot;')})"
                         style="cursor:pointer;border:2px solid ${a.is_default ? '#1d4ed8' : '#e2e8f0'};
                                border-radius:12px;padding:14px 16px;
                                background:${a.is_default ? '#eff6ff' : '#fff'};
                                transition:all 0.15s;position:relative;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                            <strong style="font-size:14px;color:#0f172a;">${a.label || 'Home'}</strong>
                            ${a.is_default ? '<span style="background:#1d4ed8;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;display:inline-flex;align-items:center;gap:3px;"><i class="fa-solid fa-check"></i> Default</span>' : ''}
                        </div>
                        <p style="margin:0;font-size:13px;color:#475569;line-height:1.5;">${a.address}</p>
                    </div>
                `).join('');
            })
            .catch(() => {
                document.getElementById('addressPickerList').innerHTML = '<p style="color:#ef4444;font-size:13px;">Failed to load addresses.</p>';
            });
    }

    function closeAddressPicker() {
        document.getElementById('addressPickerModal').style.display = 'none';
    }

    function selectAddress(addr) {
        // Fill street
        document.getElementById('streetAddress').value = addr.street_address || '';

        // Set province
        const provSel = document.getElementById('province');
        const provCode = _provinceMap[addr.province] || '';
        for (let i = 0; i < provSel.options.length; i++) {
            if (provSel.options[i].value === provCode) {
                provSel.selectedIndex = i;
                break;
            }
        }

        // Trigger cascade to load cities then barangays
        if (provCode) {
            const cityTarget  = addr.city;
            const brgyTarget  = addr.barangay;

            provSel.dispatchEvent(new Event('change'));

            const citySelect = document.getElementById('city');
            const brgySelect = document.getElementById('barangay');

            const waitCity = setInterval(() => {
                if (!citySelect.disabled && citySelect.options.length > 1) {
                    clearInterval(waitCity);
                    for (let i = 0; i < citySelect.options.length; i++) {
                        if (citySelect.options[i].text === cityTarget) {
                            citySelect.selectedIndex = i;
                            citySelect.dispatchEvent(new Event('change'));
                            break;
                        }
                    }
                    const waitBrgy = setInterval(() => {
                        if (!brgySelect.disabled && brgySelect.options.length > 1) {
                            clearInterval(waitBrgy);
                            for (let i = 0; i < brgySelect.options.length; i++) {
                                if (brgySelect.options[i].text === brgyTarget ||
                                    brgySelect.options[i].dataset.name === brgyTarget) {
                                    brgySelect.selectedIndex = i;
                                    break;
                                }
                            }
                        }
                    }, 200);
                }
            }, 200);
        }

        closeAddressPicker();
    }

    // Close picker on backdrop click
    document.getElementById('addressPickerModal').addEventListener('click', function(e) {
        if (e.target === this) closeAddressPicker();
    });
    </script>
</body>
</html>