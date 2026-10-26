<?php
session_start();
include 'db_connect.php';

// =========================================================
//  SAME-PAGE AJAX HANDLER FOR "ADD TO CART"
// =========================================================
if (isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
    header('Content-Type: application/json');
    
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
    
    if ($product_id > 0 && $quantity > 0) {
        $already_in_cart = false;
        
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            
            // Check if product is already in the cart
            $check_stmt = $conn->prepare("SELECT id FROM cart WHERE user_id = ? AND product_id = ?");
            if ($check_stmt) {
                $check_stmt->bind_param("ii", $user_id, $product_id);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $already_in_cart = true;
                } else {
                    $insert_stmt = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
                    $insert_stmt->bind_param("iii", $user_id, $product_id, $quantity);
                    $insert_stmt->execute();
                }
            }
        } else {
            // Session cart for guests
            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }
            
            if (isset($_SESSION['cart'][$product_id])) {
                $already_in_cart = true;
            } else {
                $_SESSION['cart'][$product_id] = $quantity;
            }
        }

        if ($already_in_cart) {
            echo json_encode(["success" => false, "message" => "This product is already in your cart!"]);
        } else {
            echo json_encode(["success" => true, "message" => "Successfully added to cart!"]);
        }
        exit; // Stop executing the rest of the page for this background request
    }
    
    echo json_encode(["success" => false, "message" => "Invalid product or quantity."]);
    exit;
}
// =========================================================

$is_logged_in = isset($_SESSION['user_id']);

// Fetch ACTIVE promo products where the countdown HAS NOT expired yet
$promo_query = "SELECT * FROM products WHERE is_archived = 0 AND is_promo = 1 AND discount_percent > 0 AND promo_end_date >= NOW() LIMIT 4";
$promo_result = $conn->query($promo_query);

// Get the closest expiration date from ACTIVE products to power the countdown timer in the banner
$timer_query = $conn->query("SELECT MIN(promo_end_date) as next_expiry FROM products WHERE is_archived = 0 AND is_promo = 1 AND discount_percent > 0 AND promo_end_date >= NOW()");
$timer_data = $timer_query->fetch_assoc();
$next_expiry = $timer_data['next_expiry'] ?? null;

// Fetch promo banner text from database
$promo_title = "DISCOUNTED\nPRODUCTS";
$promo_subtitle = "(LIMITED TIME OFFER)";

$settings_query = $conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('promo_title', 'promo_subtitle')");
if ($settings_query && $settings_query->num_rows > 0) {
    while ($row = $settings_query->fetch_assoc()) {
        if ($row['setting_key'] === 'promo_title') $promo_title = $row['setting_value'];
        if ($row['setting_key'] === 'promo_subtitle') $promo_subtitle = $row['setting_value'];
    }
}

// Fetch ALL ACTIVE (non-archived) products for the Catalog Section (Out of Stock moved to bottom)
$catalog_query = "
    SELECT * FROM products 
    WHERE is_archived = 0
    ORDER BY 
        (stock_status = 'Out of Stock' OR stock_quantity <= 0) ASC,
        (is_promo = 1 AND discount_percent > 0 AND promo_end_date >= NOW()) DESC, 
        id DESC
";
$catalog_result = $conn->query($catalog_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GSB Airconditioning Services - Home</title>
    <link rel="stylesheet" href="css/GSB-Homepage.css">
    <link rel="stylesheet" href="css/navbar.css?v=2">
    <!-- LINK TO YOUR NEW FOOTER CSS -->
    <link rel="stylesheet" href="css/footer.css">
    <link rel="icon" type="image/png" href="favicon.png">
    <!-- Include FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>

    <?php include 'navbar.php'; ?>

    <section class="hero-section" id="home">
        <img class="hero-bg" src="../customer/landingpageBG.png" alt="AC Technician" onerror="this.src='./GSB homepage Image/services/AC Repair.png'">
        <div class="hero-overlay"></div>

        <div class="hero-content">
            <h1>Cooling Solutions You Can Rely On</h1>
            <h2>GSB Airconditioning Services</h2>
            <p>GSB Airconditioning Services provides dependable air-conditioning products and services for homes, businesses, and commercial spaces.
                Browse and compare AC units, view specifications, prices, discounts, and availability, place orders, and request installation, cleaning, repair, or maintenance services through our website.
                For larger orders, commercial requirements, and project-based needs, our team is also available for direct coordination to provide the appropriate arrangement and assistance.</p>
            <div class="hero-slogan">
                <hr>
                <span>Feel The Cold Breeze</span>
            </div>
            <div class="hero-buttons">
                <a href="products.php" class="btn-primary">Browse Products &rarr;</a>
                <a href="services.php" class="btn-secondary">Our Services</a>
            </div>
        </div>
    </section>

    <!-- PROMO SECTION -->
    <section class="promo-section">
        <div class="promo-header">
            <h2>Discounted Products</h2>
            <a href="products.php" class="see-more-btn">See More</a>
        </div>
        
        <div class="promo-grid">
            <div class="promo-banner css-banner">
                <div class="css-banner-content">
                <h3><?php echo nl2br(htmlspecialchars($promo_title)); ?></h3>
                <p><?php echo htmlspecialchars($promo_subtitle); ?></p>
                
                    <?php if($next_expiry): ?>
                        <div class="countdown-timer" id="promo-countdown" data-end="<?php echo $next_expiry; ?>">
                            <div class="time-box"><span id="days">00</span><small>Days</small></div>
                            <div class="time-box"><span id="hours">00</span><small>Hrs</small></div>
                            <div class="time-box"><span id="mins">00</span><small>Min</small></div>
                            <div class="time-box"><span id="secs">00</span><small>Sec</small></div>
                        </div>
                    <?php else: ?>
                        <span class="banner-date">Promo runs until supplies last</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($promo_result &&$promo_result->num_rows > 0): ?>
                <?php while($promo =$promo_result->fetch_assoc()): ?>
                <?php 
                    if(!empty($promo['image'])){
                        $img_src = '../images/products/' . htmlspecialchars($promo['image']);
                    } else {
                        $img_src = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80' viewBox='0 0 24 24' fill='none' stroke='%23cbd5e1' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='3' width='18' height='18' rx='2' ry='2'%3E%3C/rect%3E%3Ccircle cx='8.5' cy='8.5' r='1.5'%3E%3C/circle%3E%3Cpolyline points='21 15 16 10 5 21'%3E%3C/polyline%3E%3C/svg%3E";
                    }
                    
                    $brand = htmlspecialchars($promo['brand'] ?? 'Brand');
                    $brand_class = ($brand === 'American Home') ? 'brand-american' : '';

                    $base_price = (float)$promo['SRP_PHP'];
                    $discount_pct = (int)$promo['discount_percent'];
                    $final_price =$base_price;
                    
                    if ($discount_pct > 0) {
                        $final_price =$base_price - ($base_price * ($discount_pct / 100));
                    }
                ?>
                <div class="promo-card" onclick="window.location.href='products.php?search=<?php echo urlencode($promo['IndoorUnitModel'] ?:$promo['SeriesName']); ?>';" style="cursor: pointer;">
                    <?php if ($discount_pct > 0): ?>
                        <div class="discount-ribbon">
                            <?php echo $discount_pct; ?>%<br><small>OFF</small>
                        </div>
                    <?php endif; ?>

                    <span class="promo-brand <?php echo $brand_class; ?>"><?php echo $brand; ?></span>
                    
                    <div class="promo-img-wrapper">
                        <img src="<?php echo $img_src; ?>" alt="<?php echo htmlspecialchars($promo['IndoorUnitModel']); ?>" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'80\' height=\'80\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%23cbd5e1\' stroke-width=\'1.5\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Crect x=\'3\' y=\'3\' width=\'18\' height=\'18\' rx=\'2\' ry=\'2\'%3E%3C/rect%3E%3Ccircle cx=\'8.5\' cy=\'8.5\' r=\'1.5\'%3E%3C/circle%3E%3Cpolyline points=\'21 15 16 10 5 21\'%3E%3C/polyline%3E%3C/svg%3E'">
                    </div>
                    
                    <div class="promo-badge">SPECIAL DISCOUNT<br><small>WHILE SUPPLIES LAST</small></div>
                    
                    <div class="promo-info">
                        <h3><?php echo htmlspecialchars($promo['IndoorUnitModel'] ?:$promo['SeriesName']); ?></h3>
                        <p><?php echo htmlspecialchars($promo['CapacityHP']); ?>HP Inverter <?php echo htmlspecialchars($promo['midea_type']); ?> Type Air Conditioner</p>
                    </div>
                    
                    <div class="promo-card-bottom">
                        <div class="promo-pricing">
                            <?php if ($discount_pct > 0): ?>
                                <span class="old-price">₱<?php echo number_format($base_price, 2); ?></span>
                            <?php endif; ?>
                            <span class="new-price">₱<?php echo number_format($final_price, 2); ?></span>
                        </div>
                        
                        <a href="products.php?search=<?php echo urlencode($promo['IndoorUnitModel']); ?>" class="cart-btn cart-btn-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="white" viewBox="0 0 16 16"><path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .491.592l-1.5 8A.5.5 0 0 1 13 12H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5zM3.102 4l1.313 7h8.17l1.313-7H3.102zM5 12a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-7 1a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm7 0a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/></svg>
                        </a>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-promo-msg">
                    <p>No promo products available right now. Check back soon!</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- PRODUCT CATALOG SECTION -->
    <section class="catalog-section" id="catalog">
        
        <div class="catalog-header">
            <h2>Product Catalog</h2>
        </div>

        <div class="catalog-filters">
            <button class="filter-btn active" data-filter="all">Recommended</button>
            <button class="filter-btn" data-filter="Midea">Midea</button>
            <button class="filter-btn" data-filter="Carrier">Carrier</button>
            <button class="filter-btn" data-filter="Daikin">Daikin</button>
        </div>

        <div class="catalog-grid">
            <?php if ($catalog_result &&$catalog_result->num_rows > 0): ?>
                <?php while($prod =$catalog_result->fetch_assoc()): ?>
                <?php 
                    $default_img = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80' viewBox='0 0 24 24' fill='none' stroke='%23cbd5e1' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='3' width='18' height='18' rx='2' ry='2'%3E%3C/rect%3E%3Ccircle cx='8.5' cy='8.5' r='1.5'%3E%3C/circle%3E%3Cpolyline points='21 15 16 10 5 21'%3E%3C/polyline%3E%3C/svg%3E";
                    
                    // Fetch single image
                    $img_src = !empty($prod['image']) ? '../images/products/' . htmlspecialchars($prod['image']) :$default_img;
                    
                    $brand = htmlspecialchars($prod['brand'] ?? 'Brand');
                    $brand_class = ($brand === 'American Home') ? 'brand-american' : '';

                    $base_price = (float)$prod['SRP_PHP'];$is_promo = isset($prod['is_promo']) ? (int)$prod['is_promo'] : 0;
                    $discount_pct = isset($prod['discount_percent']) ? (int)$prod['discount_percent'] : 0;
                    $final_price =$base_price;
                    
                    $is_active_promo = false;
                    if ($is_promo == 1 && $discount_pct > 0 && (!empty($prod['promo_end_date']) && strtotime($prod['promo_end_date']) >= time())) {$final_price = $base_price - ($base_price * ($discount_pct / 100));$is_active_promo = true;
                    }
                ?>
                <div class="promo-card catalog-item" style="display: none;" 
                     data-id="<?php echo $prod['id']; ?>"
                     data-brand="<?php echo htmlspecialchars($prod['brand'] ?? 'Other'); ?>"
                     data-series="<?php echo htmlspecialchars($prod['SeriesName']); ?>"
                     data-model="<?php echo htmlspecialchars($prod['IndoorUnitModel'] ?:$prod['SeriesName']); ?>"
                     data-hp="<?php echo htmlspecialchars($prod['CapacityHP']); ?>"
                     data-type="<?php echo ($prod['is_non_inverter'] == 1) ? 'Non-Inverter' : 'Inverter'; ?>"
                     data-cooling="<?php echo htmlspecialchars($prod['cooling_capacity'] ?? 'Not yet provided'); ?>"
                     data-warranty="<?php echo htmlspecialchars($prod['warranty'] ?? 'Not yet provided'); ?>"
                     data-status="<?php echo htmlspecialchars($prod['stock_status'] ?? 'Available'); ?>"
                     data-stock="<?php echo htmlspecialchars($prod['stock_quantity'] ?? 0); ?>" 
                     data-baseprice="<?php echo $base_price; ?>"
                     data-finalprice="<?php echo $final_price; ?>"
                     data-isactivepromo="<?php echo $is_active_promo ? '1' : '0'; ?>"
                     data-features="<?php echo htmlspecialchars($prod['features'] ?? ''); ?>"
                     data-durability="<?php echo htmlspecialchars($prod['durability'] ?? ''); ?>"
                     data-description="<?php echo htmlspecialchars($prod['description'] ?? ''); ?>"
                     data-img="<?php echo $img_src; ?>"
                     onclick="openProductModal(this)">
                    
                    <?php if ($is_active_promo): ?>
                        <div class="discount-ribbon">
                            <?php echo $discount_pct; ?>%<br><small>OFF</small>
                        </div>
                    <?php endif; ?>

                    <span class="promo-brand <?php echo $brand_class; ?>"><?php echo $brand; ?></span>
                    
                    <div class="promo-img-wrapper">
                        <img src="<?php echo $img_src; ?>" alt="<?php echo htmlspecialchars($prod['IndoorUnitModel']); ?>" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'80\' height=\'80\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%23cbd5e1\' stroke-width=\'1.5\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Crect x=\'3\' y=\'3\' width=\'18\' height=\'18\' rx=\'2\' ry=\'2\'%3E%3C/rect%3E%3Ccircle cx=\'8.5\' cy=\'8.5\' r=\'1.5\'%3E%3C/circle%3E%3Cpolyline points=\'21 15 16 10 5 21\'%3E%3C/polyline%3E%3C/svg%3E'">
                    </div>
                    
                    <div class="promo-info promo-info-spaced">
                        <h3><?php echo htmlspecialchars($prod['IndoorUnitModel'] ?:$prod['SeriesName']); ?></h3>
                        <p><?php echo htmlspecialchars($prod['CapacityHP']); ?>HP <?php echo ($prod['is_non_inverter'] == 1) ? 'Non-Inverter' : 'Inverter'; ?> <?php echo htmlspecialchars($prod['midea_type']); ?> Type</p>
                    </div>
                    
                    <div class="promo-card-bottom">
                        <div class="promo-pricing">
                            <?php if ($is_active_promo): ?>
                                <span class="old-price">₱<?php echo number_format($base_price, 2); ?></span>
                            <?php endif; ?>
                            <span class="new-price">₱<?php echo number_format($final_price, 2); ?></span>
                        </div>
                        
                        <div class="cart-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="white" viewBox="0 0 16 16"><path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .491.592l-1.5 8A.5.5 0 0 1 13 12H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5zM3.102 4l1.313 7h8.17l1.313-7H3.102zM5 12a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-7 1a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm7 0a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/></svg>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p>No products available in the catalog.</p>
            <?php endif; ?>
        </div>
        
        <div id="pagination-container" class="pagination-container"></div>

    </section>

    <!-- NEW GLOBALLY INCLUDED FOOTER -->
    <?php include 'footer.php'; ?>

    <!-- PRODUCT DETAILS MODAL -->
    <div id="productDetailsModal" class="p-modal-overlay">
        <div class="p-modal-content">
            
            <button class="p-modal-close" onclick="closeProductModal()">&times;</button>
            
            <div class="p-modal-body">
                
                <!-- LEFT SIDE: IMAGE -->
                <div class="p-modal-left">
                    
                    <!-- Main Image -->
                    <div class="p-modal-image-wrapper">
                        <div class="p-modal-image">
                            <img id="m-img" src="" alt="Product Image">
                        </div>
                    </div>

                    <!-- ACTION AREA: QUANTITY & BUTTONS -->
                    <div class="m-action-area">
                        <div class="m-quantity-row">
                            <span class="m-quantity-label">Quantity</span>
                            <div class="m-quantity-selector">
                                <button type="button" class="qty-btn" onclick="updateQuantity(-1)">−</button>
                                <input type="number" id="m-qty-input" value="1" min="1" readonly>
                                <button type="button" class="qty-btn" onclick="updateQuantity(1)">+</button>
                            </div>
                            <div class="m-stock-info">
                                <span id="m-stock-count">8</span> pieces<br>available
                            </div>
                        </div>

                        <!-- NEW BULK ORDER NOTE -->
                        <div id="bulkOrderNote" style="display: none; color: #c2410c; background-color: #ffedd5; border: 1px solid #fdba74; padding: 6px 10px; border-radius: 6px; font-size: 12px; font-weight: 500; margin: 10px 0 5px 0; text-align: center; width: 100%;">
                            <i class="fa-solid fa-box" style="margin-right: 4px;"></i> 5 or more items will be processed as a Bulk Order
                        </div>

                        <div class="m-action-buttons">
                            <button type="button" class="m-btn m-btn-outline" id="mAddToCartBtn" onclick="addToCartAction()">Add to Cart</button>
                            <button type="button" class="m-btn m-btn-solid" id="mBuyNowBtn" onclick="buyNowAction()">Buy Now</button>
                        </div>
                    </div>

                </div>

                <!-- RIGHT SIDE: DETAILS -->
                <div class="p-modal-right">
                    <div class="p-modal-header-top">
                        <span id="m-brand" class="badge-brand"></span>
                        <span id="m-status" class="badge-status"></span>
                    </div>
                    
                    <h2 id="m-series" class="p-modal-series"></h2>
                    <h3 id="m-model" class="p-modal-model"></h3>
                    
                    <div class="p-modal-pricing">
                        <span id="m-old-price" class="old-price" style="display:none;"></span>
                        <span id="m-new-price" class="new-price"></span>
                    </div>

                    <div class="p-specs-grid">
                        <div class="p-spec-box">
                            <small>Horse Power</small>
                            <strong id="m-hp"></strong>
                        </div>
                        <div class="p-spec-box">
                            <small>Type</small>
                            <strong id="m-type"></strong>
                        </div>
                        <div class="p-spec-box">
                            <small>Cooling Capacity</small>
                            <strong id="m-cooling"></strong>
                        </div>
                        <div class="p-spec-box">
                            <small>Warranty</small>
                            <strong id="m-warranty"></strong>
                        </div>
                    </div>

                    <div class="p-modal-details-box">
                        <div class="p-modal-detail-section">
                            <strong class="p-modal-detail-title">Specs</strong>
                            <div id="m-features"></div>
                        </div>
                        <div class="p-modal-detail-section">
                            <strong class="p-modal-detail-title">Features</strong>
                            <div id="m-durability"></div>
                        </div>
                        <div>
                            <strong class="p-modal-detail-title">What's in the box</strong>
                            <div id="m-description"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- LOGIC SCRIPTS -->
    <script>
        const cdElement = document.getElementById('promo-countdown');
        if(cdElement) {
            const endDate = new Date(cdElement.getAttribute('data-end').replace(' ', 'T')).getTime();
            const x = setInterval(function() {
                const now = new Date().getTime();
                const distance = endDate - now;
                if (distance <= 0) {
                    clearInterval(x);
                    window.location.reload(); 
                } else {
                    document.getElementById("days").innerText = Math.floor(distance / (1000 * 60 * 60 * 24)).toString().padStart(2, '0');
                    document.getElementById("hours").innerText = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60)).toString().padStart(2, '0');
                    document.getElementById("mins").innerText = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60)).toString().padStart(2, '0');
                    document.getElementById("secs").innerText = Math.floor((distance % (1000 * 60)) / 1000).toString().padStart(2, '0');
                }
            }, 1000);
        }

        const itemsPerPage = 24; 
        let currentPage = 1;
        let currentFilter = 'all';
        
        const catalogItems = Array.from(document.querySelectorAll('.catalog-item'));
        const paginationContainer = document.getElementById('pagination-container');
        const filterBtns = document.querySelectorAll('.filter-btn');

        function updateCatalog() {
            const filteredItems = catalogItems.filter(item => {
                const brand = item.getAttribute('data-brand');
                return currentFilter === 'all' || brand === currentFilter;
            });

            const totalPages = Math.ceil(filteredItems.length / itemsPerPage) || 1;
            if (currentPage > totalPages) currentPage = totalPages;

            catalogItems.forEach(item => item.style.display = 'none');

            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            
            filteredItems.slice(startIndex, endIndex).forEach(item => {
                item.style.display = 'flex'; 
            });

            renderPagination(totalPages);
        }

        function renderPagination(totalPages) {
            paginationContainer.innerHTML = '';
            
            if (totalPages <= 1) return;

            const prevBtn = document.createElement('button');
            prevBtn.className = 'page-btn';
            prevBtn.innerHTML = '&#10094;'; 
            prevBtn.disabled = currentPage === 1;
            prevBtn.onclick = () => { currentPage--; updateCatalog(); document.getElementById('catalog').scrollIntoView({behavior: "smooth"}); };
            paginationContainer.appendChild(prevBtn);

            for (let i = 1; i <= totalPages; i++) {
                const btn = document.createElement('button');
                btn.className = `page-btn ${i === currentPage ? 'active' : ''}`;
                btn.innerText = i;
                btn.onclick = () => { currentPage = i; updateCatalog(); document.getElementById('catalog').scrollIntoView({behavior: "smooth"}); };
                paginationContainer.appendChild(btn);
            }

            const nextBtn = document.createElement('button');
            nextBtn.className = 'page-btn';
            nextBtn.innerHTML = '&#10095;'; 
            nextBtn.disabled = currentPage === totalPages;
            nextBtn.onclick = () => { currentPage++; updateCatalog(); document.getElementById('catalog').scrollIntoView({behavior: "smooth"}); };
            paginationContainer.appendChild(nextBtn);
        }

        filterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                filterBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                currentFilter = btn.getAttribute('data-filter');
                currentPage = 1; 
                updateCatalog();
            });
        });

        updateCatalog();

        // Helper function to render text as a clean bulleted list using CSS classes instead of inline styles
        function formatAsList(value, fallback = "Not yet provided") {
            if (!value) return `<p class="modal-list-fallback">${fallback}</p>`;
            let validLines = String(value).replaceAll("<", "&lt;").replaceAll(">", "&gt;").split(/\r?\n/).filter(line => line.trim() !== "");
            if (validLines.length === 0) return `<p class="modal-list-fallback">${fallback}</p>`;
            let html = "<ul class='modal-detail-list'>";
            validLines.forEach(line => { html += `<li>${line.trim()}</li>`; });
            return html + "</ul>";
        }

        let currentProductStock = 8; // Default fallback
        let currentProductId = null; // Store ID for AJAX

        function openProductModal(card) {
            const data = card.dataset;
            currentProductId = data.id;

            // Set Single Image
            document.getElementById('m-img').src = data.img;
            document.getElementById('m-brand').innerText = data.brand;
            document.getElementById('m-series').innerText = data.series;
            document.getElementById('m-model').innerText = data.model;
            
            // ===============================================
            // DYNAMIC BADGE COLOR & TEXT BASED ON STATUS
            // ===============================================
            const statusBadge = document.getElementById('m-status');
            const statusText = data.status || 'Available';
            statusBadge.innerText = statusText;
            
            if (statusText === 'Limited Stock') {
                statusBadge.style.backgroundColor = '#fef3c7'; // yellow-100
                statusBadge.style.color = '#d97706'; // yellow-600
            } else if (statusText === 'Out of Stock') {
                statusBadge.style.backgroundColor = '#fee2e2'; // red-100
                statusBadge.style.color = '#dc2626'; // red-600
            } else {
                statusBadge.style.backgroundColor = '#dcfce7'; // green-100
                statusBadge.style.color = '#166534'; // green-800
            }

            // Set dynamic stock and reset quantity input
            currentProductStock = parseInt(data.stock) || 0; 
            document.getElementById('m-stock-count').innerText = currentProductStock;
            document.getElementById("bulkOrderNote").style.display = "none"; // Hide note

            // Disable buttons and set qty to 0 if out of stock
            const qtyInput = document.getElementById('m-qty-input');
            const addToCartBtn = document.getElementById('mAddToCartBtn');
            const buyNowBtn = document.getElementById('mBuyNowBtn');
            
            if (currentProductStock <= 0 || statusText === 'Out of Stock') {
                qtyInput.value = 0;
                addToCartBtn.disabled = true;
                buyNowBtn.disabled = true;
                addToCartBtn.style.opacity = '0.5';
                buyNowBtn.style.opacity = '0.5';
                addToCartBtn.style.cursor = 'not-allowed';
                buyNowBtn.style.cursor = 'not-allowed';
            } else {
                qtyInput.value = 1;
                addToCartBtn.disabled = false;
                buyNowBtn.disabled = false;
                addToCartBtn.style.opacity = '1';
                buyNowBtn.style.opacity = '1';
                addToCartBtn.style.cursor = 'pointer';
                buyNowBtn.style.cursor = 'pointer';
            }

            const baseP = parseFloat(data.baseprice).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const finalP = parseFloat(data.finalprice).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            const oldPriceEl = document.getElementById('m-old-price');
            const newPriceEl = document.getElementById('m-new-price');

            if(data.isactivepromo === '1') {
                oldPriceEl.innerText = '₱' + baseP;
                oldPriceEl.style.display = 'inline-block';
                newPriceEl.innerText = '₱' + finalP;
                newPriceEl.classList.add('is-promo'); 
            } else {
                oldPriceEl.style.display = 'none';
                newPriceEl.innerText = '₱' + baseP;
                newPriceEl.classList.remove('is-promo'); 
            }

            document.getElementById('m-hp').innerText = data.hp + ' HP';
            document.getElementById('m-type').innerText = data.type;
            document.getElementById('m-cooling').innerText = data.cooling || 'Not yet provided';
            document.getElementById('m-warranty').innerText = data.warranty || 'Not yet provided';

            // Inject the detailed list sections
            document.getElementById('m-features').innerHTML = formatAsList(data.features);
            document.getElementById('m-durability').innerHTML = formatAsList(data.durability);
            document.getElementById('m-description').innerHTML = formatAsList(data.description);

            document.getElementById('productDetailsModal').style.display = 'flex';
        }

        function closeProductModal() {
            document.getElementById('productDetailsModal').style.display = 'none';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('productDetailsModal');
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }

        // --- NEW QUANTITY & CART LOGIC ---
        function updateQuantity(change) {
            const qtyInput = document.getElementById('m-qty-input');
            
            // If out of stock, do not allow quantity changes
            if (currentProductStock <= 0) return;
            
            let newVal = parseInt(qtyInput.value) + change;
            
            // Prevent going below 1 or above available stock
            if (newVal < 1) newVal = 1;
            if (newVal > currentProductStock) newVal = currentProductStock;
            
            qtyInput.value = newVal;

            // Toggle bulk order note
            if (newVal >= 5) {
                document.getElementById("bulkOrderNote").style.display = "block";
            } else {
                document.getElementById("bulkOrderNote").style.display = "none";
            }
        }

        function addToCartAction() {
            if (currentProductStock <= 0) return; // Prevent action if out of stock
            
            const qty = document.getElementById('m-qty-input').value;
            if (!currentProductId) return;

            // Send POST request to the current page
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=add_to_cart&product_id=${currentProductId}&quantity=${qty}`
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message); 
            })
            .catch(error => {
                console.error('Error:', error);
                alert("Something went wrong.");
            });
        }

        function buyNowAction() {
            if (currentProductStock <= 0) return; // Prevent action if out of stock
            
            const qty = document.getElementById('m-qty-input').value;
            if (!currentProductId) return;

            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=add_to_cart&product_id=${currentProductId}&quantity=${qty}`
            })
            .then(response => response.json())
            .then(data => {
                window.location.href = `inquiry.php?product_id=${currentProductId}&qty=${qty}`; 
            })
            .catch(error => {
                console.error('Error:', error);
                window.location.href = `inquiry.php?product_id=${currentProductId}&qty=${qty}`; 
            });
        }
    </script>
</body>
</html>