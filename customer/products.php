<?php
session_start();
include 'db_connect.php'; 

// Fetch active promo products for the top carousel
$promo_query = "SELECT * FROM products WHERE is_promo = 1 AND discount_percent > 0 AND promo_end_date >= NOW() AND is_archived = 0";
$promo_result = $conn->query($promo_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GSB Airconditioning Services - Products</title>
    <link rel="icon" type="image/png" href="favicon.png">
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="css/productsssss.css">
    <link rel="stylesheet" href="css/navbar.css?v=2">
    <link rel="stylesheet" href="css/footer.css">
    <!-- Include FontAwesome for the search icon -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>

    <!-- =========================================================
         NAVBAR
    ========================================================= -->
    <?php include 'navbar.php'; ?>

        <!-- =================================================
             HOT DEALS — INSPIRATION-STYLE 3D CARD CAROUSEL
        ================================================ -->
        <?php
        $promo_items = [];
        if (isset($promo_result) && $promo_result->num_rows > 0) {
            while ($p = $promo_result->fetch_assoc()) $promo_items[] = $p;
        }
        $promo_count = count($promo_items);
        ?>

        <div class="deals-section">
            <div class="deals-header">
                <span class="deals-fire">🔥</span>
                <h2>HOT DEALS &amp; DISCOUNTS</h2>
                <p>Limited-time promos — grab them before they're gone</p>
            </div>

            <?php if ($promo_count > 0): ?>
            <div class="deals-stage" id="dealsStage">

                <?php foreach ($promo_items as $idx => $promo):
                    if (!empty($promo['image'])) {
                        $img_src = '../images/products/' . htmlspecialchars($promo['image']);
                    } else {
                        $img_src = '../images/no-image.png';
                    }
                    $base_price   = (float)$promo['SRP_PHP'];
                    $discount_pct = (int)$promo['discount_percent'];
                    $final_price  = $base_price - ($base_price * ($discount_pct / 100));
                    $savings      = $base_price - $final_price;

                    $type_text  = ($promo['is_non_inverter'] == 1) ? 'Non-Inverter' : 'Inverter';
                    $title_text = $promo['CapacityHP'] . 'HP ' . $type_text . ' ' . $promo['midea_type'] . ' Type Aircon';
                    $model_text = $promo['IndoorUnitModel'] ?: $promo['SeriesName'];

                    $stock_status = $promo['stock_status'] ?? 'Available';
                    $stock_class  = strtolower(str_replace(' ', '-', $stock_status));

                    // Specs for the stats row (mirroring the inspo Distance/Temp/Rating row)
                    $cap_hp    = htmlspecialchars($promo['CapacityHP']) . ' HP';
                    $warranty  = !empty($promo['warranty'])  ? htmlspecialchars($promo['warranty'])  : 'N/A';
                    $cool_cap  = !empty($promo['cooling_capacity']) ? htmlspecialchars($promo['cooling_capacity']) : 'N/A';
                ?>
                <div class="deals-card <?php echo $stock_class; ?>" data-index="<?php echo $idx; ?>"
                     onclick="dealsCardClick(<?php echo $idx; ?>, <?php echo $promo['id']; ?>)">

                    <!-- TOP: full-bleed product photo -->
                    <div class="dc-photo">
                        <img src="<?php echo $img_src; ?>"
                             alt="<?php echo htmlspecialchars($model_text); ?>"
                             onerror="this.src='../images/no-image.png'">
                        <!-- % off badge top-left -->
                        <div class="dc-badge"><?php echo $discount_pct; ?>%<br><small>OFF</small></div>
                    </div>

                    <!-- BOTTOM: info panel (white / light) -->
                    <div class="dc-info">

                        <!-- Title row -->
                        <div class="dc-title-row">
                            <h3 class="dc-title"><?php echo htmlspecialchars($title_text); ?></h3>
                        </div>

                        <!-- Brand + stock pill row -->
                        <div class="dc-meta-row">
                            <span class="dc-brand-pin">
                                <i class="fa-solid fa-tag"></i>
                                <?php echo htmlspecialchars($promo['brand']); ?>
                            </span>
                            <span class="dc-stock <?php echo $stock_class; ?>"><?php echo htmlspecialchars($stock_status); ?></span>
                        </div>

                        <!-- Model / series sub-line -->
                        <p class="dc-model"><?php echo htmlspecialchars($model_text); ?></p>

                        <!-- Stats row — mirrors Distance / Temp / Rating in inspo -->
                        <div class="dc-stats">
                            <div class="dc-stat">
                                <span class="dc-stat-label">Capacity</span>
                                <span class="dc-stat-value"><?php echo $cap_hp; ?></span>
                            </div>
                            <div class="dc-stat">
                                <span class="dc-stat-label">Cooling</span>
                                <span class="dc-stat-value"><?php echo $cool_cap; ?></span>
                            </div>
                            <div class="dc-stat">
                                <span class="dc-stat-label">Warranty</span>
                                <span class="dc-stat-value"><?php echo $warranty; ?></span>
                            </div>
                        </div>

                        <!-- Price row + action button -->
                        <div class="dc-footer">
                            <div class="dc-price-block">
                                <span class="dc-price-label">Sale Price</span>
                                <div class="dc-price-row">
                                    <span class="dc-old-price">₱<?php echo number_format($base_price, 2); ?></span>
                                    <span class="dc-price">₱<?php echo number_format($final_price, 2); ?></span>
                                </div>
                                <span class="dc-savings">You save ₱<?php echo number_format($savings, 2); ?></span>
                            </div>
                            <button class="dc-btn" type="button"
                                    onclick="event.stopPropagation(); openProductModal(<?php echo $promo['id']; ?>);"
                                    aria-label="View details">
                                <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        </div>

                    </div><!-- /.dc-info -->
                </div><!-- /.deals-card -->
                <?php endforeach; ?>

            </div><!-- /.deals-stage -->

            <!-- Dot navigation -->
            <div class="deals-dots" id="dealsDots">
                <?php for ($i = 0; $i < $promo_count; $i++): ?>
                    <button class="deals-dot<?php echo $i === 0 ? ' active' : ''; ?>"
                            onclick="dealsGoTo(<?php echo $i; ?>)" aria-label="Go to card <?php echo $i+1; ?>"></button>
                <?php endfor; ?>
            </div>

            <?php else: ?>
            <div class="deals-empty">No active deals right now — check back soon!</div>
            <?php endif; ?>
        </div><!-- /.deals-section -->

        <!-- MAIN CATALOG CONTAINER -->
        <div class="catalog-container">

            <!-- =================================================
                 FILTER SIDEBAR (NOW A SLIDING DRAWER)
            ================================================ -->
            <!-- Dark overlay behind the drawer -->
            <div class="filter-overlay" id="filterOverlay" onclick="toggleFilterSidebar()"></div>

            <div class="filter-box" id="filterBox">
                <!-- Close Button -->
                <button class="close-filter-btn" onclick="toggleFilterSidebar()">&times;</button>
                
                <h3>FILTER PRODUCTS</h3>

                <div style="margin-bottom: 15px;">
                    <p class="filter-title">SORT BY</p>
                    <select id="sortSelect" onchange="loadProducts(); toggleFilterSidebar();" style="width: 100%; padding: 8px; border: 1px solid #dbe2ea; border-radius: 5px; font-family: 'Poppins', sans-serif; font-size: 12px; outline: none; cursor: pointer;">
                        <option value="">Default Recommended</option>
                        <option value="name_asc">Name (A-Z)</option>
                        <option value="price_asc">Price (Low to High)</option>
                        <option value="price_desc">Price (High to Low)</option>
                    </select>
                </div>

                <hr>

                <p class="filter-title">BRAND</p>
                <label><input type="checkbox" value="Midea" class="filter-brand"> Midea</label>
                <label><input type="checkbox" value="Daikin" class="filter-brand"> Daikin</label>
                <label><input type="checkbox" value="Carrier" class="filter-brand"> Carrier</label>

                <div id="midea-extra" style="display:none;">
                    <hr>
                    <p class="filter-title">MIDEA TYPE</p>
                    <label><input type="checkbox" value="Window" class="filter-midea"> Window Type</label>
                    <label><input type="checkbox" value="Portable" class="filter-midea"> Portable</label>
                    <label><input type="checkbox" value="Split" class="filter-midea"> Split Type</label>
                </div>

                <div id="carrier-extra" style="display:none;">
                    <hr>
                    <p class="filter-title">CARRIER SERIES</p>
                    <label><input type="checkbox" value="Aura" class="filter-carrier"> Aura</label>
                    <label><input type="checkbox" value="Nexus" class="filter-carrier"> Nexus</label>
                </div>

                <div id="daikin-extra" style="display:none;">
                    <hr>
                    <p class="filter-title">DAIKIN SERIES</p>
                    <label><input type="checkbox" value="Cooling King Series Premium" class="filter-daikin"> Cooling King Series</label>
                    <label><input type="checkbox" value="D-Smart Series" class="filter-daikin"> D-Smart Series</label>
                    <label><input type="checkbox" value="D-Smart Queen Series" class="filter-daikin"> D-Smart Queen Series</label>
                    <label><input type="checkbox" value="D-Smart King Series" class="filter-daikin"> D-Smart King Series</label>
                </div>

                <hr>
                <p class="filter-title">TYPE</p>
                <label><input type="checkbox" value="1" class="filter-type"> Non-Inverter</label>
                <label><input type="checkbox" value="0" class="filter-type"> Inverter</label>

                <hr>
                <p class="filter-title">HORSEPOWER (HP)</p>
                <label><input type="checkbox" value="1.0" class="filter-hp"> 1.0 HP</label>
                <label><input type="checkbox" value="1.5" class="filter-hp"> 1.5 HP</label>
                <label><input type="checkbox" value="2.0" class="filter-hp"> 2.0 HP</label>
                <label><input type="checkbox" value="2.5" class="filter-hp"> 2.5 HP</label>

                <hr>
                <p class="filter-title">AVAILABILITY</p>
                <label><input type="checkbox" value="Available" class="filter-stock"> Available</label>
                <label><input type="checkbox" value="Limited Stock" class="filter-stock"> Limited Stock</label>

                <!-- Close sidebar when applying filters -->
                <button class="apply-btn" onclick="loadProducts(); toggleFilterSidebar();">Apply Filters</button>
                <button class="clear-btn" onclick="clearFilters()">Clear Filters</button>
            </div>

            <!-- =================================================
                 PRODUCT SIDE
            ================================================ -->
            <div class="product-side">
                
                <!-- Toggle Filter Button & Section Title -->
                <div class="product-header-controls">
                    
                    <div style="display: flex; flex-direction: column; align-items: center;">
                        <h2 class="catalog-title" id="catalogTitle" style="margin-bottom: 5px;">ALL PRODUCTS</h2>
                        <div id="activeSearchBadge" style="display: none; margin-bottom: 10px;"></div>
                    </div>
                    
                    <!-- Invisible spacer so the title stays perfectly centered! -->
                    <div style="width: 140px;" class="spacer"></div>
                </div>

                <div id="product-container"></div>
            </div>
        </div>
    </section>

    <!-- =========================================================
         MODALS
    ========================================================= -->

    <!-- 1. PRODUCT DETAILS MODAL -->
    <div class="product-modal" id="productModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeProductModal()">×</button>
            <div class="modal-body">
                <div class="modal-image-box" style="position: relative;">
                    <div id="modalPromoTimer" style="display:none; color: #d31b27; font-weight: 800; font-size: 14px; margin-bottom: 10px; text-align: center; background: #fee2e2; padding: 8px 12px; border-radius: 8px; width: 100%;"></div>
                    
                    <img id="modalImage" src="" alt="Product Image">
                    
                    <!-- Interactive Quantity Selector -->
                    <div class="modal-quantity-wrapper" id="modalQuantityWrapper">
                        <span class="qty-label">Quantity</span>
                        <div class="quantity-control">
                            <button type="button" class="qty-btn minus" onclick="updateModalQuantity(-1)">−</button>
                            <input type="number" id="modalQuantityInput" value="1" min="1" readonly>
                            <button type="button" class="qty-btn plus" onclick="updateModalQuantity(1)">+</button>
                        </div>
                        <span class="stock-left-text" id="modalStockLeftText"></span>
                    </div>

                    <!-- NEW BULK ORDER NOTE -->
                    <div id="bulkOrderNote" style="display: none; color: #c2410c; background-color: #ffedd5; border: 1px solid #fdba74; padding: 6px 10px; border-radius: 6px; font-size: 12px; font-weight: 500; margin: 10px 0 5px 0; text-align: center; width: 100%;">
                        <i class="fa-solid fa-box" style="margin-right: 4px;"></i> 5 or more items will be processed as a Bulk Order
                    </div>

                    <div class="modal-image-actions">
                        <button class="add-to-cart-btn" id="modalAddToCartBtn" onclick="addToCart(selectedProduct.id)">Add to Cart</button>
                        <button class="inquire-btn" id="modalInquireBtn" onclick="inquireProduct()">Buy Now</button>
                    </div>
                </div>
                <div class="modal-info">
                    <span id="modalBrand" class="modal-brand"></span>
                    <h2 id="modalSeries"></h2>
                    <h3 id="modalModel"></h3>
                    <span id="modalStock" class="stock-badge"></span>
                    <div class="modal-price" id="modalPrice"></div>
                    
                    <div class="modal-details-grid">
                        <div><strong>Horse Power</strong><p id="modalHP"></p></div>
                        <div><strong>Type</strong><p id="modalType"></p></div>
                        <div><strong>Cooling Capacity</strong><p id="modalCoolingCapacity"></p></div>
                        <div><strong>Warranty</strong><p id="modalWarranty"></p></div>
                    </div>
                    
                    <!-- Static Full-Width Installation Box (Gray styling, no icon) -->
                    <div class="modal-section" style="margin-top: 0; margin-bottom: 14px;">
                        <strong class="spec-label">Installation</strong>
                        <p class="spec-value-text" style="margin-top: 6px; font-size: 13.5px; color: #475569;">Free Installation Included</p>
                    </div>

                    <div class="modal-section"><strong>Specs</strong><div id="modalFeatures"></div></div>
                    <div class="modal-section"><strong>Features</strong><div id="modalDurability"></div></div>
                    <div class="modal-section"><strong>What's in the box</strong><div id="modalDescription"></div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. AUTHENTICATION MODAL -->
    <div class="auth-modal" id="authModal">
        <div class="auth-modal-content">
            <span class="auth-close-btn" onclick="closeAuthModal()">×</span>
            <h3>Authentication Required</h3>
            <p>Please log in or register to continue with your request.</p>
            <div class="auth-modal-actions">
                <button class="auth-login-btn" onclick="window.location.href='login.php'">Log In</button>
                <button class="auth-register-btn" onclick="window.location.href='register.php'">Register</button>
            </div>
        </div>
    </div>

    <!-- 3. CART MODAL -->
    <div id="cartModal" class="cart-modal">
        <div class="cart-modal-content">
            <span class="cart-close-btn" onclick="toggleCart()">×</span>
            <h3>Your Cart</h3>
            <div class="cart-header">
                <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                <label for="selectAll">Select All</label>
            </div>
            <div id="cart-items-list"></div>
            <div class="checkout-bar">
                <div><p id="cart-total">₱0.00</p></div>
                <button onclick="proceedToCheckout()">Checkout</button>
            </div>
        </div>
    </div>

    <!-- 4. BULK ORDER MODAL -->
    <div class="auth-modal" id="bulkModal">
        <div class="auth-modal-content" style="max-width: 450px; padding: 40px 30px;">
            <span class="auth-close-btn" onclick="closeBulkModal()" style="top: 20px; right: 25px;">×</span>
            <h3 style="color: #0020C2; font-size: 22px; font-weight: 700; margin-bottom: 15px;">Bulk Order Detected</h3>
            <p style="color: #475569; font-size: 15px; margin-bottom: 30px;">You have selected 5 or more products. Are you sure you want to proceed with this bulk order?</p>
            <div class="auth-modal-actions" style="display: flex; gap: 15px; justify-content: center;">
                <button onclick="closeBulkModal()" style="padding: 12px 0; border: 1px solid #cbd5e1; background: white; color: #111827; border-radius: 6px; cursor: pointer; font-weight: 600; flex: 1; font-size: 15px;">Cancel</button>
                <button id="bulkProceedBtn" style="padding: 12px 0; background: #d31b27; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; flex: 1; font-size: 15px;">Yes, Proceed</button>
            </div>
        </div>
    </div>

    <!-- =========================================================
         PRODUCT COMPARISON UI
    ========================================================= -->
    <!-- Floating Bottom Bar to Track Selection -->
    <div id="compareBar" class="compare-bar">
        <div class="compare-info">
            <span id="compareCount">0</span>/2 Products Selected
        </div>
        <div class="compare-actions">
            <button onclick="clearCompare()" class="clear-compare-btn">Clear</button>
            <button id="compareBtn" onclick="openComparison()" class="compare-btn" disabled>Compare Now</button>
        </div>
    </div>

    <!-- Bottom Drawer for Side-by-Side Comparison -->
    <div id="comparisonModal" class="comparison-modal">
        <div class="comparison-content">
            <button class="close-comparison-btn" onclick="closeComparison()">×</button>
            <h2 style="color: #0020C2; margin-top: 0; text-align: center;">Product Comparison</h2>
            
            <div id="comparisonGrid" class="comparison-grid">
                <!-- Data populated dynamically via JS -->
            </div>
        </div>
    </div>

    <!-- =========================================================
         FLOATING BUTTONS & FOOTER
    ========================================================= -->
    <!-- Floating Filter Button -->
    <div class="floating-filter" onclick="toggleFilterSidebar()">
        <i class="fa-solid fa-filter"></i> Filters
    </div>

    <!-- =========================================================
         ENHANCED FLOATING CART
    ========================================================= -->
    <div class="floating-cart" id="floatingCartBtn" onclick="toggleCart()">
        <i class="fa-solid fa-cart-shopping cart-icon"></i>
        <span id="floating-cart-count" class="cart-count">0</span>
    </div>

    <?php include 'footer.php'; ?>

    <!-- =========================================================
         SCRIPTS
    ========================================================= -->
    <script>
    /* =========================================================
       DEALS CAROUSEL — HOT DEALS & DISCOUNTS (inspo-style)
    ========================================================= */
    (function () {
        const stage = document.getElementById('dealsStage');
        if (!stage) return;

        const cards = Array.from(stage.querySelectorAll('.deals-card'));
        const total = cards.length;
        if (total === 0) return;

        let current = 0;
        let autoTimer = null;

        /* ---- Visual constants ---- */
        const GAP       = 260;   // horizontal spread from centre (px)
        const FAR_GAP   = 440;
        const SIDE_ROTX = 0;
        const SIDE_ROTY = 30;    // Y-axis tilt for side cards (deg)
        const SIDE_SCL  = 0.80;
        const FAR_SCL   = 0.62;
        const SIDE_TZ   = -110;
        const FAR_TZ    = -240;
        const SIDE_TY   = 30;
        const FAR_TY    = 55;

        function place() {
            cards.forEach((card, i) => {
                let d = i - current;
                if (total > 3) {
                    if (d >  Math.floor(total / 2)) d -= total;
                    if (d < -Math.floor(total / 2)) d += total;
                }

                card.classList.remove('dc-active','dc-left','dc-right','dc-far-left','dc-far-right');

                let tx=0, ty=0, tz=0, sc=1, ry=0, op=1, zi=1;

                if (d === 0) {
                    tx=0; ty=0; tz=0; sc=1; ry=0; op=1; zi=10;
                    card.classList.add('dc-active');
                } else if (d === -1) {
                    tx=-GAP; ty=SIDE_TY; tz=SIDE_TZ; sc=SIDE_SCL; ry=SIDE_ROTY; op=0.82; zi=7;
                    card.classList.add('dc-left');
                } else if (d === 1) {
                    tx= GAP; ty=SIDE_TY; tz=SIDE_TZ; sc=SIDE_SCL; ry=-SIDE_ROTY; op=0.82; zi=7;
                    card.classList.add('dc-right');
                } else if (d <= -2) {
                    tx=-FAR_GAP; ty=FAR_TY; tz=FAR_TZ; sc=FAR_SCL; ry=SIDE_ROTY*1.3; op=0.38; zi=4;
                    card.classList.add('dc-far-left');
                } else {
                    tx= FAR_GAP; ty=FAR_TY; tz=FAR_TZ; sc=FAR_SCL; ry=-SIDE_ROTY*1.3; op=0.38; zi=4;
                    card.classList.add('dc-far-right');
                }

                card.style.transform = `translateX(${tx}px) translateY(${ty}px) translateZ(${tz}px) rotateY(${ry}deg) scale(${sc})`;
                card.style.opacity   = op;
                card.style.zIndex    = zi;
            });

            /* sync dots */
            document.querySelectorAll('#dealsDots .deals-dot').forEach((d, i) => {
                d.classList.toggle('active', i === current);
            });
        }

        function next() { current = (current + 1) % total; place(); }
        function prev() { current = (current - 1 + total) % total; place(); }

        function startAuto() { autoTimer = setInterval(next, 4500); }
        function stopAuto()  { clearInterval(autoTimer); }

        stage.addEventListener('mouseenter', stopAuto);
        stage.addEventListener('mouseleave', startAuto);

        /* touch swipe */
        let tx0 = 0;
        stage.addEventListener('touchstart', e => { tx0 = e.touches[0].clientX; stopAuto(); }, { passive:true });
        stage.addEventListener('touchend',   e => {
            const dx = e.changedTouches[0].clientX - tx0;
            if (Math.abs(dx) > 40) dx < 0 ? next() : prev();
            startAuto();
        });

        /* expose */
        window.dealsGoTo = function(idx) { current = idx; place(); };

        /* init */
        place();
        startAuto();
    })();

    /* Card click: side cards navigate; active card opens modal */
    window.dealsCardClick = function(idx, productId) {
        const cards = Array.from(document.querySelectorAll('#dealsStage .deals-card'));
        if (!cards[idx]) return;
        if (cards[idx].classList.contains('dc-active')) {
            openProductModal(productId);
        } else {
            window.dealsGoTo(idx);
        }
    };

    /* USER & STATE */
    const USER_ID = <?php echo json_encode($_SESSION['user_id'] ?? null); ?>;
    let productsData = [];
    let cartItemsData = [];
    let selectedProduct = null;
    let modalTimerInterval = null; 
    let compareList = []; 

    /* Pagination State Variables */
    let currentPage = 1;
    const itemsPerPage = 12; 
    let initialLoad = true;

    /* PAGE INITIALIZATION */
    window.onload = function () {
        setupFilters();
        const urlParams = new URLSearchParams(window.location.search);

        if (urlParams.has('brand')) {
            let brandRaw = urlParams.get('brand');
            let brandCheckbox = document.querySelector(`.filter-brand[value="${brandRaw.charAt(0).toUpperCase() + brandRaw.slice(1).toLowerCase()}"]`);
            if (brandCheckbox) { brandCheckbox.checked = true; brandCheckbox.dispatchEvent(new Event('change')); }
        }
        if (urlParams.has('midea_type')) {
            let mideaCheckbox = document.querySelector(`.filter-midea[value="${urlParams.get('midea_type')}"]`);
            if (mideaCheckbox) mideaCheckbox.checked = true;
        }
        if (urlParams.has('series')) {
            let daikinCheckbox = document.querySelector(`.filter-daikin[value="${urlParams.get('series')}"]`);
            if (daikinCheckbox) daikinCheckbox.checked = true;
        }

        loadProducts();
        if (USER_ID) updateCartCount();
    };

    /* FILTER SIDEBAR TOGGLE */
    function toggleFilterSidebar() {
        document.getElementById('filterBox').classList.toggle('open');
        
        let overlay = document.getElementById('filterOverlay');
        if (overlay.style.display === 'block') {
            overlay.style.opacity = '0';
            setTimeout(() => overlay.style.display = 'none', 300);
        } else {
            overlay.style.display = 'block';
            setTimeout(() => overlay.style.opacity = '1', 10);
        }
    }

    /* FILTER SETUP */
    function setupFilters() {
        let midea = document.querySelector('.filter-brand[value="Midea"]');
        let daikin = document.querySelector('.filter-brand[value="Daikin"]');
        let carrier = document.querySelector('.filter-brand[value="Carrier"]');
        
        let mideaBox = document.getElementById("midea-extra");
        let daikinBox = document.getElementById("daikin-extra");
        let carrierBox = document.getElementById("carrier-extra");

        midea.addEventListener("change", function () {
            mideaBox.style.display = this.checked ? "block" : "none";
            if (!this.checked) document.querySelectorAll(".filter-midea").forEach(el => el.checked = false);
        });

        daikin.addEventListener("change", function () {
            daikinBox.style.display = this.checked ? "block" : "none";
            if (!this.checked) document.querySelectorAll(".filter-daikin").forEach(el => el.checked = false);
        });

        carrier.addEventListener("change", function () {
            carrierBox.style.display = this.checked ? "block" : "none";
            if (!this.checked) document.querySelectorAll(".filter-carrier").forEach(el => el.checked = false);
        });
    }

    /* SAFE TEXT & FORMATTING */
    function safeText(value, fallback = "Not yet provided") {
        if (!value) return fallback;
        return String(value).replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");
    }

    function formatAsList(value, fallback = "Not yet provided") {
        if (!value) return `<p>${fallback}</p>`;
        let validLines = String(value).replaceAll("<", "&lt;").replaceAll(">", "&gt;").split(/\r?\n/).filter(line => line.trim() !== "");
        if (validLines.length === 0) return `<p>${fallback}</p>`;
        let html = "<ul class='modal-list'>";
        validLines.forEach(line => { html += `<li>${line.trim()}</li>`; });
        return html + "</ul>";
    }

    /* PRODUCT IMAGE */
    function getProductImage(product) {
        let image = product.image ? String(product.image).trim() : "";
        if (image === "") return "../images/no-image.png";
        if (image.length >= 32) return "../images/products/" + image;

        let brand = product.brand ? String(product.brand).toLowerCase().trim() : "";
        let model = product.IndoorUnitModel ? String(product.IndoorUnitModel).trim() : "";
        const gsbProductPics = ["FP-51ARA006HMNV-N5", "MWF1-07CMNS-NCO", "MWF1-09CMNS-NCO", "MWF2-12CMN8-NB9", "MWTF2-18CMN1-NCO", "MWTF1-21CMN1-NCO", "MWF1-09CRN8-NCO", "MWF2-12CRN8-NB9", "MWF2-18CRN8-NC1", "FP-51ARA010HEIV-N4", "FP-51ARA015HEIV-N4"];

        if (gsbProductPics.includes(model)) return "../images/products/Gsb_productpics/" + image;
        if (brand === "midea") return "../images/products/midea/" + image;
        if (brand === "daikin") return "../images/products/daikin/" + image;
        if (brand === "carrier") return "../images/products/carrier/" + image;
        return "../images/products/" + image;
    }


    /* CANCEL SEARCH */
    function cancelSearch() {
        let url = new URL(window.location);
        url.searchParams.delete('search');
        window.history.pushState({}, '', url);
        
        let navSearchInput = document.querySelector('.nav-search-input');
        if (navSearchInput) navSearchInput.value = '';
        
        let clearBtn = document.getElementById('navSearchClear');
        if (clearBtn) clearBtn.style.display = 'none';

        loadProducts();
    }

    /* LOAD MAIN PRODUCTS */
    function loadProducts() {
        let params = new URLSearchParams();
        
        const pageUrl = new URLSearchParams(window.location.search);
        let searchBadge = document.getElementById("activeSearchBadge");
        let catalogTitle = document.getElementById("catalogTitle");
        
        if (pageUrl.has('search') && pageUrl.get('search').trim() !== "") {
            let searchTerm = pageUrl.get('search');
            params.append("search", searchTerm);
            
            if (catalogTitle) catalogTitle.innerText = "SEARCH RESULTS";
            if (searchBadge) {
                searchBadge.style.display = "block";
                searchBadge.innerHTML = `
                <span style="background: #fee2e2; color: #d31b27; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; border: 1px solid #fca5a5;">
                    Results for "${safeText(searchTerm)}" 
                    <i class="fa-solid fa-circle-xmark" style="cursor: pointer; font-size: 15px;" onclick="cancelSearch()"></i>
                </span>`;
            }
        } else {
            if (catalogTitle) catalogTitle.innerText = "ALL PRODUCTS";
            if (searchBadge) searchBadge.style.display = "none";
        }
        
        if (document.getElementById("sortSelect").value) params.append("sort", document.getElementById("sortSelect").value);

        document.querySelectorAll(".filter-brand:checked").forEach(b => params.append("brand[]", b.value));
        document.querySelectorAll(".filter-type:checked").forEach(t => params.append("is_non_inverter[]", t.value));
        document.querySelectorAll(".filter-midea:checked").forEach(m => params.append("midea_type[]", m.value));
        document.querySelectorAll(".filter-daikin:checked").forEach(d => params.append("series[]", d.value));
        document.querySelectorAll(".filter-carrier:checked").forEach(c => params.append("carrier_series[]", c.value));
        document.querySelectorAll(".filter-hp:checked").forEach(h => params.append("hp[]", h.value));
        document.querySelectorAll(".filter-stock:checked").forEach(s => params.append("stock_status[]", s.value));

        fetch("fetch_products.php?" + params.toString())
        .then(response => response.json())
        .then(data => {
            let container = document.getElementById("product-container");
            if (!data.Products || data.Products.length === 0) {
                container.innerHTML = `<div class="no-products" style="text-align:center; padding: 50px;"><h3>No Products Found</h3><p>Try changing your filters or search term.</p></div>`;
                productsData = [];
                return;
            }

            productsData = data.Products;
            currentPage = 1; 
            renderProducts();

            if (initialLoad) {
                if (pageUrl.has('search')) {
                    let searchWord = pageUrl.get('search').toLowerCase();
                    let matchedProduct = productsData.find(p => 
                        (p.IndoorUnitModel && p.IndoorUnitModel.toLowerCase() === searchWord) || 
                        (p.SeriesName && p.SeriesName.toLowerCase() === searchWord)
                    );
                    if (matchedProduct) {
                        openProductModal(matchedProduct.id);
                    }
                }
                initialLoad = false; 
            }
        })
        .catch(error => {
            document.getElementById("product-container").innerHTML = `<div class="no-products"><h3>Error Loading Products</h3></div>`;
        });
    }

    /* RENDER PAGINATED PRODUCTS UI */
    function renderProducts() {
        let container = document.getElementById("product-container");
        
        let totalPages = Math.ceil(productsData.length / itemsPerPage);
        let startIndex = (currentPage - 1) * itemsPerPage;
        let endIndex = startIndex + itemsPerPage;
        
        let pagedData = productsData.slice(startIndex, endIndex);

        let html = `<div class="product-grid">`;

        pagedData.forEach(product => {
            let stockClass = (product.stock_status || "Available").toLowerCase().replaceAll(" ", "-");
            let basePrice = parseFloat(product.SRP_PHP || 0);
            let isPromo = parseInt(product.is_promo || 0) === 1;
            let discountPct = parseInt(product.discount_percent || 0);
            
            let displayPrice = basePrice;
            let oldPriceHtml = "";
            let promoBadgeHtml = "";
            
            if(isPromo && discountPct > 0) {
                displayPrice = basePrice - (basePrice * (discountPct / 100));
                oldPriceHtml = `<span class="old-price">₱${basePrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>`;
                promoBadgeHtml = `<div class="discount-ribbon">${discountPct}%<br><small>OFF</small></div>`;
            }

            let typeText = (product.is_non_inverter == 1) ? 'Non-Inverter' : 'Inverter';
            let titleText = `${safeText(product.CapacityHP)}HP ${typeText} ${safeText(product.midea_type)} Type Aircon`;
            let modelText = safeText(product.IndoorUnitModel) || safeText(product.SeriesName);

            html += `
                <div class="card ${stockClass}" onclick="openProductModal(${product.id})">
                    ${promoBadgeHtml}

                    <div class="product-image-wrapper">
                        <img src="${getProductImage(product)}" alt="${titleText}" onerror="this.onerror=null; this.src='../images/no-image.png';">
                    </div>

                    <div class="card-content">
                        <!-- 1. Brand badge -->
                        <span class="card-brand-badge">${safeText(product.brand, "No Brand")}</span>
                        <!-- 2. Product name — bold navy, matches modal h2 -->
                        <h3 class="card-title">${titleText}</h3>
                        <!-- 3. Model code — muted subtitle -->
                        <p class="card-model">${modelText}</p>

                        <div class="card-bottom">
                            <div class="price-section">
                                ${oldPriceHtml}
                                <p class="price">₱${displayPrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>
                            </div>
                            <span class="stock-badge ${stockClass}">${safeText(product.stock_status || "Available")}</span>

                            <div class="action-row">
                                <label class="compare-checkbox-label" onclick="event.stopPropagation();">
                                    <input type="checkbox" ${compareList.some(p => p.id == product.id) ? 'checked' : ''} onchange="toggleCompare(${product.id}, this)">
                                    Compare
                                </label>
                                <button class="view-details-btn" type="button" onclick="event.stopPropagation(); openProductModal(${product.id});">View Details</button>
                            </div>
                        </div>
                    </div>
                </div>`;
        });
        
        html += `</div>`;

        if (totalPages > 1) {
            html += `<div class="pagination-container">`;
            html += `<button class="page-btn prev-next" onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>&laquo; Prev</button>`;

            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
                    html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="changePage(${i})">${i}</button>`;
                } else if (i === currentPage - 2 || i === currentPage + 2) {
                    html += `<span class="page-dots">...</span>`;
                }
            }

            html += `<button class="page-btn prev-next" onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>Next &raquo;</button>`;
            html += `</div>`;
        }

        container.innerHTML = html;
    }

    function changePage(page) {
        let totalPages = Math.ceil(productsData.length / itemsPerPage);
        if (page < 1 || page > totalPages) return;
        
        currentPage = page;
        renderProducts();
        document.querySelector('.catalog-container').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* CART LOGIC */
    function openAuthModal() { closeProductModal(); document.getElementById("authModal").classList.add("show"); }
    function closeAuthModal() { document.getElementById("authModal").classList.remove("show"); }
    function closeBulkModal() { document.getElementById("bulkModal").classList.remove("show"); }

    function updateCartCount() {
        if (!USER_ID) return;
        fetch("get_cart_count.php")
        .then(res => res.json())
        .then(data => {
            let count = document.getElementById("floating-cart-count");
            if (count) {
                count.innerText = data.cartCount || 0;
                let cartBtn = document.getElementById("floatingCartBtn");
                cartBtn.classList.remove("cart-pop");
                void cartBtn.offsetWidth; 
                cartBtn.classList.add("cart-pop");
            }
        });
    }

    function addToCart(productId) {
        if (!USER_ID) return openAuthModal();
        let selectedQty = parseInt(document.getElementById("modalQuantityInput").value) || 1;
        
        fetch("add_to_cart.php", { method: "POST", body: JSON.stringify({ user_id: USER_ID, product_id: productId, quantity: selectedQty }), headers: { "Content-Type": "application/json" } })
        .then(res => res.json()).then(result => {
            if (result.success) { updateCartCount(); loadCartItems(); closeProductModal(); toggleCart(); } 
            else { alert(result.message || "Failed to add to cart."); }
        });
    }

    function inquireProduct() {
        if (!USER_ID) return openAuthModal();
        if (!selectedProduct) return;
        
        let selectedQty = parseInt(document.getElementById("modalQuantityInput").value) || 1;
        
        // --- BULK ORDER CHECK FOR "BUY NOW" ---
        if (selectedQty >= 5) {
            closeProductModal(); 
            
            // Re-route the Yes button to proceed to bulk_order.php carrying the specific product data
            let proceedBtn = document.getElementById("bulkProceedBtn");
            proceedBtn.onclick = function() {
                window.location.href = `bulk_order.php?product=${encodeURIComponent(selectedProduct.IndoorUnitModel)}&brand=${encodeURIComponent(selectedProduct.brand)}&quantity=${selectedQty}`;
            };
            
            document.getElementById("bulkModal").classList.add("show");
            return; // Stop here, wait for modal confirmation
        }
        
        window.location.href = `inquiry.php?product=${encodeURIComponent(selectedProduct.IndoorUnitModel)}&brand=${encodeURIComponent(selectedProduct.brand)}&quantity=${selectedQty}`;
    }

    // UPDATED: Strictly hide floating cart icon when the cart modal opens, and show it when it closes
    function toggleCart() { 
        let cartModal = document.getElementById("cartModal");
        let floatingCartBtn = document.getElementById("floatingCartBtn");
        
        cartModal.classList.toggle("open"); 
        
        if (cartModal.classList.contains("open")) {
            floatingCartBtn.style.display = "none";
        } else {
            floatingCartBtn.style.display = "flex";
        }
        
        loadCartItems(); 
    }

    function loadCartItems() {
        let list = document.getElementById("cart-items-list");
        let totalEl = document.getElementById("cart-total");
        
        if (!USER_ID) {
            list.innerHTML = `<div style="text-align:center; padding:20px;"><p>Please log in.</p><button onclick="openAuthModal()" style="padding:10px 20px; background:#0020C2; color:white; border:none; border-radius:6px;">Login Now</button></div>`;
            totalEl.innerText = ""; 
            return;
        }
        
        fetch("get_cart_items.php")
        .then(res => res.json())
        .then(data => {
            cartItemsData = data.cartItems || [];
            
            if (cartItemsData.length === 0) { 
                list.innerHTML = "<p>Your cart is empty.</p>"; 
                totalEl.innerText = "Total: ₱0.00"; 
                return; 
            }
            
            list.innerHTML = "";
            
            cartItemsData.forEach(item => {
                let basePrice = parseFloat(item.SRP_PHP || 0);
                let activePrice = basePrice;
                let isDiscounted = (item.is_promo == 1 && item.discount_percent > 0);
                
                if (isDiscounted && item.promo_end_date && item.promo_end_date !== '0000-00-00 00:00:00') {
                    let promoEnd = new Date(item.promo_end_date.replace(' ', 'T'));
                    if (new Date() > promoEnd) isDiscounted = false; 
                }

                if (isDiscounted) {
                    activePrice = basePrice - (basePrice * (item.discount_percent / 100));
                }

                let subtotal = activePrice * parseInt(item.quantity || 1); 
                
                let priceHtml = '';
                if (isDiscounted) {
                    priceHtml = `<span style="text-decoration: line-through; color: #94a3b8; font-size: 11px; display:block;">₱${basePrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                                 <span style="color:#d31b27; font-weight:bold; font-size:14px;">₱${activePrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>`;
                } else {
                    priceHtml = `<span style="font-weight:bold; font-size:14px;">₱${activePrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>`;
                }

                list.innerHTML += `
                    <div class="cart-product-card">
                        <input type="checkbox" class="cart-item-checkbox" data-product-id="${item.id}" onclick="updateCheckoutTotal()">
                        <img src="${getProductImage(item)}" alt="Product">
                        <div class="cart-product-info">
                            <p class="cart-brand">${safeText(item.brand)}</p>
                            <h4>${safeText(item.SeriesName)}</h4>
                            <div style="margin: 4px 0;">${priceHtml}</div>
                            <div class="qty-control">
                                <button onclick="changeQty(${item.cart_id}, -1)">−</button><span>${item.quantity || 1}</span><button onclick="changeQty(${item.cart_id}, 1)">+</button>
                            </div>
                            <p class="subtotal" style="color: #64748b; font-size: 12px; margin-top: 4px;">Subtotal: ₱${subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>
                        </div>
                        <button class="remove-btn" onclick="removeItem(${item.cart_id})">🗑</button>
                    </div>`;
            });
            
            updateCheckoutTotal();
        });
    }

    function updateCheckoutTotal() {
        let selectedTotal = 0;
        
        document.querySelectorAll(".cart-item-checkbox:checked").forEach(cb => {
            let item = cartItemsData.find(c => c.id == cb.dataset.productId);
            if (item) { 
                let activePrice = parseFloat(item.SRP_PHP || 0);
                let isDiscounted = (item.is_promo == 1 && item.discount_percent > 0);
                
                if (isDiscounted && item.promo_end_date && item.promo_end_date !== '0000-00-00 00:00:00') {
                    let promoEnd = new Date(item.promo_end_date.replace(' ', 'T'));
                    if (new Date() > promoEnd) isDiscounted = false; 
                }
                
                if (isDiscounted) {
                    activePrice = activePrice - (activePrice * (item.discount_percent / 100));
                }
                
                selectedTotal += activePrice * parseInt(item.quantity || 1); 
            }
        });
        
        document.getElementById("cart-total").innerText = "Total: ₱" + selectedTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function proceedToCheckout() {
        let selectedItems = []; 
        let totalQuantity = 0; 

        document.querySelectorAll(".cart-item-checkbox:checked").forEach(cb => {
            let item = cartItemsData.find(c => c.id == cb.dataset.productId);
            if (item) { 
                selectedItems.push(item); 
                let qty = parseInt(item.quantity || 1);
                totalQuantity += qty; 
            }
        });

        if (selectedItems.length === 0) return alert("Please select at least one product.");

        // --- BULK ORDER CHECK FOR "CART CHECKOUT" ---
        if (totalQuantity >= 5) {
            toggleCart(); 
            
            // Re-route the Yes button to proceed to bulk_order.php carrying the cart items
            let proceedBtn = document.getElementById("bulkProceedBtn");
            proceedBtn.onclick = function() {
                window.location.href = `bulk_order.php?product=${encodeURIComponent(selectedItems.map(i => i.IndoorUnitModel).join(", "))}&brand=${encodeURIComponent(selectedItems.map(i => i.brand).filter((v, idx, a) => a.indexOf(v) === idx).join(", "))}&quantity=${encodeURIComponent(selectedItems.map(i => i.quantity || 1).join(", "))}`;
            };
            
            document.getElementById("bulkModal").classList.add("show"); 
            return;
        }

        window.location.href = `inquiry.php?product=${encodeURIComponent(selectedItems.map(i => i.IndoorUnitModel).join(", "))}&brand=${encodeURIComponent(selectedItems.map(i => i.brand).filter((v, idx, a) => a.indexOf(v) === idx).join(", "))}&quantity=${encodeURIComponent(selectedItems.map(i => i.quantity || 1).join(", "))}`;
    }

    function updateModalQuantity(change) {
        let input = document.getElementById("modalQuantityInput");
        let maxVal = parseInt(input.max) || 1;
        let currentVal = parseInt(input.value) || 1;
        
        let newVal = currentVal + change;
        
        if (newVal >= 1 && newVal <= maxVal) {
            input.value = newVal;

            // Toggle bulk order note
            if (newVal >= 5) {
                document.getElementById("bulkOrderNote").style.display = "block";
            } else {
                document.getElementById("bulkOrderNote").style.display = "none";
            }
        }
    }

    /* MODAL AND TIMER LOGIC */
    function openProductModal(productId) {
        selectedProduct = productsData.find(p => p.id == productId);
        if (!selectedProduct) return;

        if (modalTimerInterval) clearInterval(modalTimerInterval);

        let stockClass = (selectedProduct.stock_status || "Available").toLowerCase().replaceAll(" ", "-");
        let basePrice = parseFloat(selectedProduct.SRP_PHP || 0);
        let finalPrice = basePrice;
        let priceHtml = "";
        
        let timerContainer = document.getElementById("modalPromoTimer");
        timerContainer.style.display = "none"; 

        if (selectedProduct.is_promo == 1 && selectedProduct.discount_percent > 0) {
            finalPrice = basePrice - (basePrice * (selectedProduct.discount_percent / 100));
            priceHtml = `<span style="text-decoration: line-through; color: #94a3b8; font-size: 14px; margin-right: 10px;">₱${basePrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span> <span style="color:#d31b27;">₱${finalPrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>`;
            
            if (selectedProduct.promo_end_date && selectedProduct.promo_end_date !== '0000-00-00 00:00:00') {
                let endDate = new Date(selectedProduct.promo_end_date.replace(' ', 'T')).getTime();
                
                const updateTimer = () => {
                    let now = new Date().getTime();
                    let distance = endDate - now;

                    if (distance <= 0) {
                        clearInterval(modalTimerInterval);
                        timerContainer.innerHTML = "Promo Expired!";
                    } else {
                        let days = Math.floor(distance / (1000 * 60 * 60 * 24)).toString().padStart(2, '0');
                        let hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60)).toString().padStart(2, '0');
                        let mins = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60)).toString().padStart(2, '0');
                        let secs = Math.floor((distance % (1000 * 60)) / 1000).toString().padStart(2, '0');
                        
                        timerContainer.innerHTML = `⏳ Promo ends in: ${days}d ${hours}h ${mins}m ${secs}s`;
                    }
                };

                if (endDate > new Date().getTime()) {
                    timerContainer.style.display = "block";
                    updateTimer(); 
                    modalTimerInterval = setInterval(updateTimer, 1000); 
                }
            }
        } else {
            priceHtml = "₱" + basePrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        document.getElementById("modalImage").src = getProductImage(selectedProduct);
        document.getElementById("modalBrand").innerHTML = safeText(selectedProduct.brand);
        document.getElementById("modalSeries").innerHTML = safeText(selectedProduct.SeriesName);
        document.getElementById("modalModel").innerHTML = safeText(selectedProduct.IndoorUnitModel);
        document.getElementById("modalStock").className = "stock-badge " + stockClass;
        document.getElementById("modalStock").innerHTML = safeText(selectedProduct.stock_status || "Available");
        document.getElementById("modalPrice").innerHTML = priceHtml;
        document.getElementById("modalHP").innerHTML = safeText(selectedProduct.CapacityHP) + " HP";
        document.getElementById("modalType").innerHTML = selectedProduct.is_non_inverter == 1 ? "Non-Inverter" : "Inverter";
        document.getElementById("modalCoolingCapacity").innerHTML = safeText(selectedProduct.cooling_capacity);
        document.getElementById("modalWarranty").innerHTML = safeText(selectedProduct.warranty);
        document.getElementById("modalFeatures").innerHTML = formatAsList(selectedProduct.features);
        document.getElementById("modalDurability").innerHTML = formatAsList(selectedProduct.durability);
        document.getElementById("modalDescription").innerHTML = formatAsList(selectedProduct.description);
        
        let inquireBtn = document.getElementById("modalInquireBtn");
        let addToCartBtn = document.getElementById("modalAddToCartBtn");
        let isOutOfStock = selectedProduct.stock_status === "Out of Stock";
        
        if (isOutOfStock) { 
            inquireBtn.innerHTML = "Out of Stock"; 
            inquireBtn.disabled = true; 
            inquireBtn.classList.add("disabled"); 
            addToCartBtn.disabled = true;
            addToCartBtn.classList.add("disabled");
        } else { 
            inquireBtn.innerHTML = "Buy Now"; 
            inquireBtn.disabled = false; 
            inquireBtn.classList.remove("disabled"); 
            addToCartBtn.disabled = false;
            addToCartBtn.classList.remove("disabled");
        }
        
        // Reset and setup the quantity selector based on exact stock
        let currentStock = parseInt(selectedProduct.stock_quantity) || 0;
        let qtyInput = document.getElementById("modalQuantityInput");
        let qtyWrapper = document.getElementById("modalQuantityWrapper");
        let stockLeftText = document.getElementById("modalStockLeftText");

        // BULLETPROOF FALLBACK: Show selector if marked 'Available' even if DB says 0 (old data)
        if (!isOutOfStock) {
            qtyWrapper.style.display = "flex";
            qtyInput.value = 1;
            qtyInput.max = currentStock > 0 ? currentStock : 10; // Fallback max to 10 if stock is missing
            stockLeftText.innerHTML = currentStock > 0 ? `${currentStock} piece${currentStock > 1 ? 's' : ''} available` : `Available`;
            
            // Hide the bulk note every time a new product is opened
            document.getElementById("bulkOrderNote").style.display = "none";
        } else {
            qtyWrapper.style.display = "none";
        }
        
        document.getElementById("productModal").classList.add("show");
    }

    function closeProductModal() { 
        document.getElementById("productModal").classList.remove("show"); 
        if (modalTimerInterval) clearInterval(modalTimerInterval); 
    }
    
    document.addEventListener("click", function (e) {
        if (e.target === document.getElementById("productModal")) closeProductModal();
        if (e.target === document.getElementById("authModal")) closeAuthModal();
        if (e.target === document.getElementById("bulkModal")) closeBulkModal();
        if (e.target === document.getElementById("comparisonModal")) closeComparison();
    });

    function clearFilters() {
        document.querySelectorAll("input[type=checkbox]").forEach(el => el.checked = false);
        document.getElementById("sortSelect").value = "";
        ["midea-extra", "daikin-extra", "carrier-extra"].forEach(id => document.getElementById(id).style.display = "none");
        
        let navSearchInput = document.querySelector('.nav-search-input');
        if (navSearchInput) navSearchInput.value = '';

        window.history.pushState({}, document.title, window.location.pathname);
        loadProducts();
        toggleFilterSidebar(); 
    }

    function changeQty(cartId, change) { fetch("update_cart_quantity.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ cart_id: cartId, change: change }) }).then(res => res.json()).then(data => { if (data.success) { loadCartItems(); updateCartCount(); } }); }
    function removeItem(cartId) { if (confirm("Remove this item?")) fetch("remove_from_cart.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ cart_id: cartId }) }).then(res => res.json()).then(data => { if (data.success) { loadCartItems(); updateCartCount(); } }); }
    function toggleSelectAll(source) { document.querySelectorAll(".cart-item-checkbox").forEach(cb => cb.checked = source.checked); updateCheckoutTotal(); }
    
    /* =========================================================
       COMPARISON LOGIC
    ========================================================= */
    function toggleCompare(productId, element) {
        if (element.checked) {
            if (compareList.length >= 2) {
                alert("You can only compare up to 2 products at a time.");
                element.checked = false;
                return;
            }
            let prod = productsData.find(p => p.id == productId);
            if (prod) compareList.push(prod);
        } else {
            compareList = compareList.filter(p => p.id != productId);
        }
        updateCompareBar();
    }

    function updateCompareBar() {
        let bar = document.getElementById("compareBar");
        let countEl = document.getElementById("compareCount");
        let btn = document.getElementById("compareBtn");
        
        countEl.innerText = compareList.length;
        bar.classList.toggle("show", compareList.length > 0);
        btn.disabled = compareList.length !== 2;
    }

    function clearCompare() {
        compareList = [];
        document.querySelectorAll(".compare-checkbox-label input").forEach(cb => cb.checked = false);
        updateCompareBar();
        closeComparison();
    }

    function openComparison() {
        if (compareList.length !== 2) return;
        
        let p1 = compareList[0];
        let p2 = compareList[1];
        
        const getPriceHtml = (p) => {
            let base = parseFloat(p.SRP_PHP || 0);
            if (p.is_promo == 1 && p.discount_percent > 0) {
                let disc = base - (base * (p.discount_percent / 100));
                return `<span style="text-decoration:line-through; font-size:14px; color:#94a3b8;">₱${base.toLocaleString(undefined, {minimumFractionDigits: 2})}</span><br>₱${disc.toLocaleString(undefined, {minimumFractionDigits: 2})}`;
            }
            return `₱${base.toLocaleString(undefined, {minimumFractionDigits: 2})}`;
        };

        document.getElementById("comparisonGrid").innerHTML = `
            <table class="compare-table">
                <tr>
                    <th></th>
                    <td>
                        <img src="${getProductImage(p1)}" alt="Product 1">
                        <h3>${safeText(p1.IndoorUnitModel)}</h3>
                        <p class="comp-price">${getPriceHtml(p1)}</p>
                    </td>
                    <td>
                        <img src="${getProductImage(p2)}" alt="Product 2">
                        <h3>${safeText(p2.IndoorUnitModel)}</h3>
                        <p class="comp-price">${getPriceHtml(p2)}</p>
                    </td>
                </tr>
                <tr><th>Brand</th><td>${safeText(p1.brand)}</td><td>${safeText(p2.brand)}</td></tr>
                <tr><th>Series</th><td>${safeText(p1.SeriesName)}</td><td>${safeText(p2.SeriesName)}</td></tr>
                <tr><th>Horsepower (HP)</th><td>${safeText(p1.CapacityHP)} HP</td><td>${safeText(p2.CapacityHP)} HP</td></tr>
                <tr><th>Motor Type</th><td>${p1.is_non_inverter == 1 ? 'Non-Inverter' : 'Inverter'}</td><td>${p2.is_non_inverter == 1 ? 'Non-Inverter' : 'Inverter'}</td></tr>
                <tr><th>Cooling Capacity</th><td>${safeText(p1.cooling_capacity)}</td><td>${safeText(p2.cooling_capacity)}</td></tr>
                <tr><th>Warranty</th><td>${safeText(p1.warranty)}</td><td>${safeText(p2.warranty)}</td></tr>
                <tr><th>Key Features</th><td>${formatAsList(p1.features, "N/A")}</td><td>${formatAsList(p2.features, "N/A")}</td></tr>
                <tr><th>Durability</th><td>${formatAsList(p1.durability, "N/A")}</td><td>${formatAsList(p2.durability, "N/A")}</td></tr>
            </table>
        `;
        
        document.getElementById("comparisonModal").classList.add("show");
    }

    function closeComparison() {
        document.getElementById("comparisonModal").classList.remove("show");
    }

    /* =========================================================
       NEW: CONTINUOUS ROPE SCROLL LOGIC (UPDATED - NO DUPLICATION)
    ========================================================= */
    document.addEventListener("DOMContentLoaded", function() {
        const carousel = document.getElementById("discounted-carousel");
        
        if (carousel && carousel.children.length > 0) {
            let isHovered = false;
            let scrollDirection = 1; 

            // Smooth pixel-by-pixel animation
            function scrollContinuously() {
                if (!isHovered) {
                    // Only scroll if there are enough items to actually overflow the screen
                    if (carousel.scrollWidth > carousel.clientWidth) {
                        carousel.scrollLeft += scrollDirection; 
                        
                        // Ping-pong back and forth gracefully instead of making fake copies
                        if (carousel.scrollLeft + carousel.clientWidth >= carousel.scrollWidth) {
                            scrollDirection = -1; // Reverse left
                        } else if (carousel.scrollLeft <= 0) {
                            scrollDirection = 1; // Reverse right
                        }
                    }
                }
                requestAnimationFrame(scrollContinuously);
            }
            
            // Start the animation
            requestAnimationFrame(scrollContinuously);

            // Pause the rope when the user puts their mouse over a product
            carousel.addEventListener('mouseenter', () => isHovered = true);
            carousel.addEventListener('mouseleave', () => isHovered = false);
        }
    });
    </script>
</body>
</html>