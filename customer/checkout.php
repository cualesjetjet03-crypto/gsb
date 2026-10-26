<?php
session_start();
include 'db_connect.php';

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// ─── Accept selected cart_ids via POST (from the cart panel) ────────────────
// The cart panel posts: selected_cart_ids[] = [cart_id, cart_id, ...]
$selected_ids = array_map('intval', $_POST['selected_cart_ids'] ?? []);

if (empty($selected_ids)) {
    header("Location: products.php?error=no_items_selected");
    exit();
}

// ─── Fetch the selected cart items (only items belonging to this user) ───────
$placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
$types        = str_repeat('i', count($selected_ids) + 1); // +1 for user_id

$sql = "
    SELECT
        c.id        AS cart_id,
        c.quantity,
        p.id        AS product_id,
        p.brand,
        p.SeriesName,
        p.IndoorUnitModel,
        p.CapacityHP,
        p.SRP_PHP,
        p.is_promo,
        p.discount_percent,
        p.promo_end_date,
        p.stock_status,
        p.stock_quantity,
        p.image
    FROM cart c
    INNER JOIN products p ON c.product_id = p.id
    WHERE c.user_id = ?
      AND c.id IN ($placeholders)
      AND p.is_archived = 0
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, $user_id, ...array_values($selected_ids));
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($items)) {
    header("Location: products.php?error=invalid_cart_items");
    exit();
}

// ─── Calculate totals ─────────────────────────────────────────────────────────
$subtotal = 0;
$line_items = [];

foreach ($items as $item) {
    $base  = (float)$item['SRP_PHP'];
    $price = $base;
    $is_discounted = false;

    if ($item['is_promo'] && $item['discount_percent'] > 0 && !empty($item['promo_end_date'])) {
        if (strtotime($item['promo_end_date']) >= time()) {
            $price         = $base - ($base * ($item['discount_percent'] / 100));
            $is_discounted = true;
        }
    }

    $line_total  = $price * (int)$item['quantity'];
    $subtotal   += $line_total;

    $line_items[] = array_merge($item, [
        'active_price'  => $price,
        'is_discounted' => $is_discounted,
        'line_total'    => $line_total,
    ]);
}

// Fetch user info for pre-filling the form
$u_stmt = $conn->prepare("SELECT fullname, email, phone, address FROM users WHERE id = ? LIMIT 1");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$customer = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();

function e($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout — GSB Airconditioning Services</title>
    <link rel="stylesheet" href="css/navbar.css?v=2">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="css/GSB-Homepage.css">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { padding-top: 100px; background: #f5f7fb; font-family: 'Poppins', sans-serif; }
        .checkout-wrapper { max-width: 1000px; margin: 40px auto; padding: 0 20px; }
        .checkout-wrapper h1 { font-size: 26px; color: #001b94; margin-bottom: 24px; }

        .checkout-grid { display: grid; grid-template-columns: 1fr 380px; gap: 28px; }
        @media (max-width: 800px) { .checkout-grid { grid-template-columns: 1fr; } }

        .checkout-card { background: #fff; border-radius: 14px; padding: 28px; border: 1px solid #e5eaf3; box-shadow: 0 4px 15px rgba(0,0,0,0.04); }
        .checkout-card h2 { font-size: 17px; font-weight: 700; color: #0f172a; margin: 0 0 18px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 500px) { .form-row { grid-template-columns: 1fr; } }
        .form-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
        .form-group label { font-size: 12px; font-weight: 600; color: #475569; }
        .form-group input, .form-group select, .form-group textarea {
            padding: 10px 12px; border: 1px solid #dbe2ea; border-radius: 8px;
            font-size: 14px; font-family: inherit; outline: none;
            transition: opacity 0.3s, background 0.3s, border-color 0.3s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #001b94; }

        /* ── Locked form state (before terms accepted) ── */
        .form-locked .form-group input,
        .form-locked .form-group select,
        .form-locked .form-group textarea {
            opacity: 0.42;
            background: #f1f5f9;
            pointer-events: none;
            cursor: not-allowed;
            user-select: none;
        }
        .form-locked .form-group label { opacity: 0.55; }
        .form-locked { position: relative; }
        .form-locked::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 14px;
            background: transparent;
            z-index: 1;
            cursor: not-allowed;
        }

        /* Lock banner inside the delivery card */
        .lock-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 11px 15px;
            font-size: 13px;
            color: #92400e;
            font-weight: 500;
            margin-bottom: 18px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .lock-banner:hover { background: #fef3c7; }
        .lock-banner i { color: #d97706; font-size: 16px; flex-shrink: 0; }
        .lock-banner .open-terms-link {
            color: #001b94; font-weight: 700; text-decoration: underline; margin-left: 4px;
        }

        /* ── Terms agreed state ── */
        .terms-agreed-bar {
            display: none;
            align-items: center;
            gap: 8px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 10px 15px;
            font-size: 13px;
            color: #166534;
            font-weight: 600;
            margin-bottom: 18px;
        }
        .terms-agreed-bar i { font-size: 15px; }

        /* Order summary */
        .order-item { display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .order-item:last-child { border-bottom: none; }
        .order-item img { width: 64px; height: 64px; object-fit: contain; background: #f8fafc; border-radius: 8px; padding: 6px; border: 1px solid #e5eaf3; }
        .order-item-info { flex: 1; }
        .order-item-info strong { display: block; font-size: 13px; color: #0f172a; }
        .order-item-info span { font-size: 12px; color: #64748b; }
        .order-item-price { text-align: right; }
        .order-item-price .price { font-size: 15px; font-weight: 700; color: #001b94; }
        .order-item-price .old { font-size: 11px; color: #cbd5e1; text-decoration: line-through; display: block; }
        .order-total-row { display: flex; justify-content: space-between; align-items: center; padding: 14px 0 0; border-top: 2px solid #e5eaf3; margin-top: 8px; }
        .order-total-row span { font-size: 15px; font-weight: 600; color: #475569; }
        .order-total-row strong { font-size: 22px; font-weight: 800; color: #001b94; }

        .place-order-btn {
            width: 100%; padding: 15px; background: #001b94; color: #fff;
            font-size: 16px; font-weight: 700; border: none; border-radius: 10px;
            cursor: pointer; margin-top: 18px; transition: background 0.2s, opacity 0.3s;
        }
        .place-order-btn:hover:not(:disabled) { background: #00126b; }
        .place-order-btn:disabled { opacity: 0.45; cursor: not-allowed; }

        .note-box { background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 8px; padding: 12px 14px; font-size: 12px; color: #3730a3; margin-top: 14px; }

        /* ═══════════════════════════════════════════
           TERMS & PRIVACY MODAL
        ═══════════════════════════════════════════ */
        .terms-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(10, 20, 80, 0.60);
            backdrop-filter: blur(3px);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: tmFadeIn 0.3s ease;
        }
        @keyframes tmFadeIn { from { opacity: 0; } to { opacity: 1; } }

        .terms-modal {
            background: #fff;
            border-radius: 18px;
            width: 100%;
            max-width: 640px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
            animation: tmSlideUp 0.32s cubic-bezier(0.34, 1.56, 0.64, 1);
            overflow: hidden;
        }
        @keyframes tmSlideUp { from { transform: translateY(40px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        /* Modal header */
        .terms-modal-header {
            background: linear-gradient(135deg, #001b94 0%, #0040cc 100%);
            padding: 22px 28px 18px;
            flex-shrink: 0;
        }
        .terms-modal-header h2 {
            margin: 0 0 4px;
            font-size: 18px;
            font-weight: 800;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .terms-modal-header p {
            margin: 0;
            font-size: 12px;
            color: rgba(255,255,255,0.72);
        }

        /* Tabs */
        .terms-tabs {
            display: flex;
            background: #f8fafc;
            border-bottom: 1px solid #e5eaf3;
            flex-shrink: 0;
        }
        .terms-tab-btn {
            flex: 1;
            padding: 13px 10px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: color 0.2s, border-color 0.2s;
            font-family: inherit;
        }
        .terms-tab-btn:hover { color: #001b94; }
        .terms-tab-btn.active {
            color: #001b94;
            border-bottom-color: #001b94;
            background: #fff;
        }

        /* Scrollable content */
        .terms-modal-body {
            flex: 1;
            overflow-y: auto;
            padding: 24px 28px;
            font-size: 13.5px;
            line-height: 1.75;
            color: #334155;
        }
        .terms-modal-body::-webkit-scrollbar { width: 5px; }
        .terms-modal-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .terms-tab-content { display: none; }
        .terms-tab-content.active { display: block; }

        .terms-modal-body h3 {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin: 20px 0 6px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5eaf3;
        }
        .terms-modal-body h3:first-child { margin-top: 0; }
        .terms-modal-body p { margin: 0 0 10px; }
        .terms-modal-body ul { margin: 0 0 10px 18px; padding: 0; }
        .terms-modal-body li { margin-bottom: 4px; }

        /* Scroll progress hint */
        .scroll-hint {
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            padding: 6px 0 0;
            flex-shrink: 0;
        }
        .scroll-hint.hidden { display: none; }

        /* Modal footer */
        .terms-modal-footer {
            border-top: 1px solid #e5eaf3;
            padding: 18px 28px;
            background: #f8fafc;
            flex-shrink: 0;
        }
        .terms-agree-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 16px;
        }
        .terms-agree-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            flex-shrink: 0;
            accent-color: #001b94;
            cursor: pointer;
        }
        .terms-agree-row label {
            font-size: 13px;
            color: #334155;
            line-height: 1.5;
            cursor: pointer;
        }
        .terms-agree-row label a {
            color: #001b94;
            font-weight: 600;
            text-decoration: underline;
        }

        .terms-proceed-btn {
            width: 100%;
            padding: 13px;
            background: #001b94;
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: background 0.2s, opacity 0.2s;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .terms-proceed-btn:disabled {
            opacity: 0.40;
            cursor: not-allowed;
        }
        .terms-proceed-btn:hover:not(:disabled) { background: #00126b; }

        /* Read-progress bar */
        .read-progress-bar {
            height: 3px;
            background: #e5eaf3;
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 14px;
        }
        .read-progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #001b94, #0066ff);
            border-radius: 2px;
            transition: width 0.25s ease;
        }
        .read-progress-label {
            font-size: 11px;
            color: #94a3b8;
            text-align: right;
            margin-top: -10px;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<!-- ═══════════════════════════════════════════════
     TERMS & PRIVACY POLICY MODAL GATE
═══════════════════════════════════════════════ -->
<div class="terms-modal-overlay" id="termsModalOverlay">
    <div class="terms-modal" role="dialog" aria-modal="true" aria-labelledby="termsModalTitle">

        <!-- Header -->
        <div class="terms-modal-header">
            <h2 id="termsModalTitle">
                <i class="fa-solid fa-shield-halved"></i>
                Terms & Privacy Policy
            </h2>
            <p>Please read and accept our terms before proceeding to checkout.</p>
        </div>

        <!-- Tabs -->
        <div class="terms-tabs">
            <button class="terms-tab-btn active" onclick="switchTab('terms', this)">
                <i class="fa-solid fa-file-contract"></i> Terms of Use &amp; Sale
            </button>
            <button class="terms-tab-btn" onclick="switchTab('privacy', this)">
                <i class="fa-solid fa-lock"></i> Privacy Policy
            </button>
        </div>

        <!-- Scrollable body -->
        <div class="terms-modal-body" id="termsScrollBody">

            <!-- Terms of Use & Sale -->
            <div class="terms-tab-content active" id="tab-terms">
                <h3>1. Acceptance of Terms</h3>
                <p>By placing an order through the GSB Airconditioning Services website, you confirm that you have read, understood, and agree to be bound by these Terms of Use and Sale. If you do not agree, do not proceed with your purchase.</p>

                <h3>2. Products & Availability</h3>
                <p>All products listed are subject to availability. GSB Airconditioning Services reserves the right to limit quantities, cancel orders, or refuse service at our discretion. Product images and descriptions are for reference only; actual units may vary slightly.</p>

                <h3>3. Pricing & Payment</h3>
                <ul>
                    <li>All prices are in Philippine Peso (₱) and are inclusive of applicable taxes unless stated otherwise.</li>
                    <li>GSB reserves the right to correct pricing errors at any time.</li>
                    <li>Payment must be completed before order processing begins.</li>
                    <li>Accepted methods: Cash on Delivery, GCash, and Bank Transfer.</li>
                </ul>

                <h3>4. Order Processing & Delivery</h3>
                <p>Orders are subject to confirmation by our team. Delivery schedules are estimated and may be affected by location, availability, and circumstances beyond our control. We will contact you to confirm your delivery schedule after your order is placed.</p>

                <h3>5. Installation Services</h3>
                <p>Free installation is included for qualifying purchases. Installation must be scheduled within 30 days of product delivery. GSB technicians will perform all installations. Any modifications to your property required for installation are at the customer's responsibility unless otherwise agreed.</p>

                <h3>6. Returns & Warranty</h3>
                <ul>
                    <li>Products may be returned within 7 days of delivery for manufacturing defects only.</li>
                    <li>Warranty coverage follows the manufacturer's terms (typically 1 year parts and service, 3 years compressor).</li>
                    <li>Warranty is void if the unit is damaged by misuse, unauthorized repair, or improper installation.</li>
                </ul>

                <h3>7. Limitation of Liability</h3>
                <p>GSB Airconditioning Services shall not be liable for indirect, incidental, or consequential damages arising from the use or inability to use our products or services. Our maximum liability is limited to the amount paid for the specific product or service.</p>

                <h3>8. Governing Law</h3>
                <p>These terms are governed by the laws of the Republic of the Philippines. Any disputes shall be subject to the exclusive jurisdiction of the courts of the Philippines.</p>

                <h3>9. Changes to Terms</h3>
                <p>GSB reserves the right to modify these terms at any time. Continued use of our services after changes constitutes acceptance of the updated terms.</p>
            </div>

            <!-- Privacy Policy -->
            <div class="terms-tab-content" id="tab-privacy">
                <h3>1. Information We Collect</h3>
                <p>When you use our services, we collect the following personal information:</p>
                <ul>
                    <li><strong>Account data:</strong> Full name, email address, username, password (hashed).</li>
                    <li><strong>Contact data:</strong> Phone number, delivery address, barangay, city, province.</li>
                    <li><strong>Transaction data:</strong> Products ordered, quantities, order history, service bookings.</li>
                    <li><strong>Usage data:</strong> Page visits and interactions within the GSB website.</li>
                </ul>

                <h3>2. How We Use Your Information</h3>
                <ul>
                    <li>To process and fulfill your orders and service bookings.</li>
                    <li>To contact you about order confirmations, delivery schedules, and service updates.</li>
                    <li>To send notifications about the status of your requests.</li>
                    <li>To improve our products and services.</li>
                    <li>To comply with legal obligations.</li>
                </ul>

                <h3>3. Data Sharing</h3>
                <p>We do not sell, trade, or rent your personal information to third parties. We may share limited information with assigned technicians solely for the purpose of fulfilling your service request.</p>

                <h3>4. Data Security</h3>
                <p>We implement industry-standard security measures including password hashing, encrypted connections (HTTPS), and access controls to protect your data. However, no method of transmission over the internet is 100% secure.</p>

                <h3>5. Data Retention</h3>
                <p>We retain your personal data for as long as your account is active or as needed to provide services. You may request deletion of your account and associated data by contacting us directly.</p>

                <h3>6. Your Rights</h3>
                <p>Under the Data Privacy Act of 2012 (Republic Act 10173), you have the right to:</p>
                <ul>
                    <li>Access and obtain a copy of your personal data.</li>
                    <li>Correct inaccurate or incomplete data.</li>
                    <li>Object to or withdraw consent for data processing.</li>
                    <li>Request deletion or blocking of your data.</li>
                </ul>

                <h3>7. Cookies</h3>
                <p>Our website uses session cookies to maintain your login state and shopping cart. These cookies are essential for the website to function and are not used for advertising tracking.</p>

                <h3>8. Contact</h3>
                <p>For privacy-related concerns, please contact us at <strong>gsbairconservice01@gmail.com</strong>. We will respond within 5 business days.</p>
            </div>

        </div>

        <!-- Scroll hint -->
        <div class="scroll-hint" id="scrollHint">
            <i class="fa-solid fa-chevron-down"></i> Scroll down to read the full document
        </div>

        <!-- Footer -->
        <div class="terms-modal-footer">
            <!-- Read progress -->
            <div class="read-progress-bar">
                <div class="read-progress-fill" id="readProgressFill"></div>
            </div>
            <div class="read-progress-label" id="readProgressLabel">Scroll to read — 0% read</div>

            <!-- Agreement checkbox -->
            <div class="terms-agree-row">
                <input type="checkbox" id="termsAgreeChk" onchange="onAgreeChange()" disabled>
                <label for="termsAgreeChk">
                    I have read and agree to the <a href="#" onclick="switchTab('terms', document.querySelector('.terms-tab-btn')); return false;">GSB Terms of Use and Sale</a> and <a href="#" onclick="switchTab('privacy', document.querySelectorAll('.terms-tab-btn')[1]); return false;">Privacy Policy</a>.
                </label>
            </div>

            <button class="terms-proceed-btn" id="termsProceedBtn" disabled onclick="acceptTerms()">
                <i class="fa-solid fa-lock-open"></i>
                Proceed to Fill Details
            </button>
        </div>
    </div>
</div>
<!-- END TERMS MODAL -->

<div class="checkout-wrapper">
    <h1><i class="fa-solid fa-bag-shopping"></i> Checkout</h1>

    <form method="POST" action="process_order.php" id="checkoutForm">
        <!-- Pass selected cart IDs to the order processor -->
        <?php foreach ($selected_ids as $cid): ?>
            <input type="hidden" name="selected_cart_ids[]" value="<?= (int)$cid ?>">
        <?php endforeach; ?>

        <div class="checkout-grid">

            <!-- LEFT: Delivery details -->
            <div>
                <div class="checkout-card form-locked" id="deliveryCard">

                    <!-- Lock banner (shown before terms accepted) -->
                    <div class="lock-banner" id="lockBanner" onclick="showTermsModal()">
                        <i class="fa-solid fa-lock"></i>
                        <span>Please review &amp; accept our Terms before filling in your details.</span>
                        <span class="open-terms-link">View Terms &rarr;</span>
                    </div>

                    <!-- Agreed banner (shown after terms accepted) -->
                    <div class="terms-agreed-bar" id="agreedBar">
                        <i class="fa-solid fa-circle-check"></i>
                        Terms &amp; Privacy Policy accepted — form unlocked.
                    </div>

                    <h2><i class="fa-solid fa-location-dot"></i> Delivery Information</h2>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="delivery_name" value="<?= e($customer['fullname']) ?>" required disabled>
                        </div>
                        <div class="form-group">
                            <label>Phone Number *</label>
                            <input type="tel" name="delivery_phone" value="<?= e($customer['phone']) ?>"
                                   pattern="^09[0-9]{9}$" placeholder="09XXXXXXXXX" required disabled>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="delivery_email" value="<?= e($customer['email']) ?>" required disabled>
                    </div>

                    <div class="form-group">
                        <label>Delivery Address *</label>
                        <textarea name="delivery_address" rows="3" required disabled><?= e($customer['address']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Order Notes (optional)</label>
                        <textarea name="order_notes" rows="2" placeholder="Any special instructions..." disabled></textarea>
                    </div>
                </div>

                <div class="checkout-card form-locked" id="paymentCard" style="margin-top: 20px;">
                    <h2><i class="fa-solid fa-credit-card"></i> Payment Method</h2>
                    <div class="form-group">
                        <label>Select Payment</label>
                        <select name="payment_method" required disabled>
                            <option value="">— Choose —</option>
                            <option value="Cash on Delivery">Cash on Delivery</option>
                            <option value="GCash">GCash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Order summary -->
            <div>
                <div class="checkout-card">
                    <h2><i class="fa-solid fa-receipt"></i> Order Summary</h2>

                    <?php foreach ($line_items as $item): ?>
                    <div class="order-item">
                        <img src="../images/products/<?= e($item['image']) ?>"
                             alt="<?= e($item['SeriesName']) ?>"
                             onerror="this.src='../images/no-image.png'">
                        <div class="order-item-info">
                            <strong><?= e($item['brand']) ?> <?= e($item['SeriesName']) ?></strong>
                            <span><?= e($item['IndoorUnitModel']) ?> · <?= e($item['CapacityHP']) ?>HP</span>
                            <span>Qty: <?= (int)$item['quantity'] ?></span>
                        </div>
                        <div class="order-item-price">
                            <?php if ($item['is_discounted']): ?>
                                <span class="old">₱<?= number_format($item['SRP_PHP'], 2) ?></span>
                            <?php endif; ?>
                            <span class="price">₱<?= number_format($item['line_total'], 2) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="order-total-row">
                        <span>Total</span>
                        <strong>₱<?= number_format($subtotal, 2) ?></strong>
                    </div>
                </div>

                <button type="submit" class="place-order-btn" id="placeOrderBtn" disabled>
                    <i class="fa-solid fa-check"></i> Place Order
                </button>

                <div class="note-box">
                    <i class="fa-solid fa-circle-info"></i>
                    Orders are subject to confirmation. Our team will contact you to arrange delivery.
                </div>
            </div>
        </div>
    </form>
</div>

<?php include 'footer.php'; ?>

<script>
/* ═══════════════════════════════════════════
   TERMS MODAL LOGIC
═══════════════════════════════════════════ */

const scrollBody    = document.getElementById('termsScrollBody');
const progressFill  = document.getElementById('readProgressFill');
const progressLabel = document.getElementById('readProgressLabel');
const scrollHint    = document.getElementById('scrollHint');
const agreeChk      = document.getElementById('termsAgreeChk');
const proceedBtn    = document.getElementById('termsProceedBtn');

let termsRead    = false;
let privacyRead  = false;

// ── Track scroll progress per tab ──
function updateScrollProgress() {
    const el     = scrollBody;
    const pct    = el.scrollHeight <= el.clientHeight
                   ? 100
                   : Math.min(100, Math.round((el.scrollTop / (el.scrollHeight - el.clientHeight)) * 100));

    progressFill.style.width  = pct + '%';
    progressLabel.textContent = `Scroll to read — ${pct}% read`;

    if (pct >= 80) {
        scrollHint.classList.add('hidden');
        // Mark current tab as read
        const activeTab = document.querySelector('.terms-tab-btn.active').dataset.tab;
        if (activeTab === 'terms')   termsRead   = true;
        if (activeTab === 'privacy') privacyRead = true;

        // Enable checkbox only after BOTH tabs have been scrolled ≥80%
        if (termsRead && privacyRead) {
            agreeChk.disabled = false;
        }
    }
}

scrollBody.addEventListener('scroll', updateScrollProgress);

// ── Tab switching ──
function switchTab(tabId, btn) {
    // Deactivate all
    document.querySelectorAll('.terms-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.terms-tab-content').forEach(c => c.classList.remove('active'));

    // Activate chosen
    btn.classList.add('active');
    btn.dataset.tab = tabId;
    document.getElementById('tab-' + tabId).classList.add('active');

    // Reset scroll to top of new tab
    scrollBody.scrollTop = 0;
    progressFill.style.width  = '0%';
    progressLabel.textContent = 'Scroll to read — 0% read';
    scrollHint.classList.remove('hidden');

    updateScrollProgress(); // handles case where content < viewport
}

// Set data-tab on initial buttons
document.querySelectorAll('.terms-tab-btn').forEach((btn, i) => {
    btn.dataset.tab = i === 0 ? 'terms' : 'privacy';
});

// ── Check initial scroll (short content) ──
updateScrollProgress();

// ── Checkbox change ──
function onAgreeChange() {
    proceedBtn.disabled = !agreeChk.checked;
}

// ── Accept and unlock form ──
function acceptTerms() {
    if (!agreeChk.checked) return;

    // Hide modal
    const overlay = document.getElementById('termsModalOverlay');
    overlay.style.opacity    = '0';
    overlay.style.transition = 'opacity 0.3s ease';
    setTimeout(() => overlay.style.display = 'none', 300);

    // Unlock all form fields
    document.querySelectorAll('#deliveryCard input, #deliveryCard select, #deliveryCard textarea,\
                                #paymentCard  input, #paymentCard  select, #paymentCard  textarea')
            .forEach(el => el.disabled = false);

    // Remove lock class & overlay
    document.getElementById('deliveryCard').classList.remove('form-locked');
    document.getElementById('paymentCard').classList.remove('form-locked');

    // Swap banners
    document.getElementById('lockBanner').style.display  = 'none';
    document.getElementById('agreedBar').style.display   = 'flex';

    // Enable Place Order button
    document.getElementById('placeOrderBtn').disabled = false;

    // Scroll to form
    document.getElementById('deliveryCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Re-open modal from lock banner ──
function showTermsModal() {
    const overlay = document.getElementById('termsModalOverlay');
    overlay.style.opacity  = '1';
    overlay.style.display  = 'flex';
}

// ── Prevent form submission if terms not accepted (safety net) ──
document.getElementById('checkoutForm').addEventListener('submit', function(e) {
    if (document.getElementById('placeOrderBtn').disabled) {
        e.preventDefault();
        showTermsModal();
    }
});
</script>
</body>
</html>
