<?php
session_start();

if(!isset($_SESSION['admin'])){
    header("Location: admin_login.php");
    exit();
}

include "includes/db_connect.php";

function e($value){
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function badgeClass($value){
    return strtolower(str_replace(' ', '-', trim((string)$value)));
}

// ==========================================
// HANDLE ARCHIVE & RESTORE (UNARCHIVE)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_action'])) {
    $action = $_POST['archive_action'];
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;

    if ($product_id > 0) {
        if ($action === 'archive') {
            $stmt = $conn->prepare("UPDATE products SET is_archived = 1 WHERE id = ?");
            $stmt->bind_param("i", $product_id);
            if ($stmt->execute()) {
                header("Location: admin_products.php?view=active&success=" . urlencode("Product moved to Archived Products successfully!"));
                exit();
            }
        } elseif ($action === 'unarchive') {
            $stmt = $conn->prepare("UPDATE products SET is_archived = 0 WHERE id = ?");
            $stmt->bind_param("i", $product_id);
            if ($stmt->execute()) {
                header("Location: admin_products.php?view=archived&success=" . urlencode("Product restored to Active Products successfully!"));
                exit();
            }
        }
    }

    header("Location: admin_products.php?error=" . urlencode("Failed to update product archive status."));
    exit();
}

// ==========================================
// HANDLE PROMO BANNER UPDATE
// ==========================================
if (isset($_POST['update_banner'])) {
    $title = $_POST['promo_title'] ?? '';
    $subtitle = $_POST['promo_subtitle'] ?? '';

    $stmt = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('promo_title', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("ss", $title, $title);
    $stmt->execute();

    $stmt2 = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('promo_subtitle', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt2->bind_param("ss", $subtitle, $subtitle);
    $stmt2->execute();

    header("Location: admin_products.php?success=" . urlencode("Promo banner updated successfully!"));
    exit();
}

// Fetch current banner text for the modal
$promo_title    = "DISCOUNTED\nPRODUCTS";
$promo_subtitle = "(LIMITED TIME OFFER)";

$settings_query = $conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('promo_title', 'promo_subtitle')");
if ($settings_query && $settings_query->num_rows > 0) {
    while ($row = $settings_query->fetch_assoc()) {
        if ($row['setting_key'] === 'promo_title')    $promo_title    = $row['setting_value'];
        if ($row['setting_key'] === 'promo_subtitle') $promo_subtitle = $row['setting_value'];
    }
}

// ==========================================
// ARCHIVE TOGGLE & SEARCH
// ==========================================
$view = $_GET['view'] ?? 'active';
$is_archived_filter = ($view === 'archived') ? 1 : 0;
$search = $_GET['search'] ?? '';

if($search !== ''){
    $like = "%" . $search . "%";
    $stmt = $conn->prepare("SELECT * FROM products WHERE (SeriesName LIKE ? OR IndoorUnitModel LIKE ? OR brand LIKE ? OR midea_type LIKE ?) AND is_archived = ? ORDER BY id DESC");
    $stmt->bind_param("ssssi", $like, $like, $like, $like, $is_archived_filter);
    $stmt->execute();
    $products = $stmt->get_result();
} else {
    $stmt = $conn->prepare("SELECT * FROM products WHERE is_archived = ? ORDER BY id DESC");
    $stmt->bind_param("i", $is_archived_filter);
    $stmt->execute();
    $products = $stmt->get_result();
}

$pending_count_result = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status='Pending'");
$pending_count = $pending_count_result ? (int)$pending_count_result->fetch_assoc()['total'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Products - GSB Admin</title>
<link rel="stylesheet" href="includes/css/admin_dashboard.css">
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
/* Styling for Batch Action Button */
.batch-promo-btn {
    background-color: #d31b27;
    color: white;
    border: none;
    padding: 10px 16px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background 0.2s;
}
.batch-promo-btn:hover {
    background-color: #b0141f;
}
.form-group {
    transition: opacity 0.3s;
}

/* Tabs Styling */
.product-tabs {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    border-bottom: 2px solid #e2e8f0;
}
.product-tab {
    padding: 10px 15px;
    text-decoration: none;
    color: #64748b;
    font-weight: 600;
    font-size: 15px;
    border-bottom: 3px solid transparent;
    transition: 0.2s ease;
    margin-bottom: -2px;
}
.product-tab:hover {
    color: #0020C2;
}
.product-tab.active {
    color: #0020C2;
    border-bottom-color: #0020C2;
}

/* IMAGE PREVIEW & REMOVE BUTTON STYLES */
.preview-container {
    position: relative;
    display: inline-block;
    margin-bottom: 10px;
    margin-top: 5px;
}
.preview-container img {
    width: 80px;
    height: 80px;
    object-fit: contain;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    padding: 5px;
}
.remove-img-btn {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #d31b27;
    color: white;
    border: none;
    border-radius: 50%;
    width: 22px;
    height: 22px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    transition: 0.2s;
}
.remove-img-btn:hover {
    background: #b0141f;
    transform: scale(1.1);
}

/* ARCHIVE / RESTORE CONFIRMATION MODAL */
.confirm-modal-card {
    max-width: 420px;
    text-align: center;
    padding: 30px 25px;
    border-radius: 12px;
    position: relative;
}
.confirm-icon-wrap {
    font-size: 46px;
    color: #ef4444;
    margin-bottom: 12px;
}
.confirm-icon-wrap.restore-icon {
    color: #16a34a;
}
.confirm-modal-card h2 {
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 10px;
}
.confirm-modal-card p {
    font-size: 14px;
    color: #64748b;
    line-height: 1.5;
    margin-bottom: 24px;
}
.confirm-modal-actions {
    display: flex;
    justify-content: center;
    gap: 12px;
}
.confirm-cancel-btn {
    background: #ffffff;
    color: #1e40af;
    border: 1px solid #cbd5e1;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
}
.confirm-cancel-btn:hover {
    background: #f1f5f9;
}
.confirm-proceed-btn {
    background: #ef4444;
    color: #ffffff;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
}
.confirm-proceed-btn:hover {
    background: #dc2626;
}
.confirm-proceed-btn.restore-btn {
    background: #16a34a;
}
.confirm-proceed-btn.restore-btn:hover {
    background: #15803d;
}
</style>
</head>
<body>
<div class="admin-wrapper">

    <?php include "includes/admin_sidebar.php"; ?>

    <main class="main-content">
        
        <!-- Centralized Topbar Include -->
        <?php include 'includes/topbar.php'; ?>

        <section class="page-header row-header">
            <div>
                <h1>Products</h1>
                <p>Manage all your products and inventory.</p>
            </div>
            <div class="product-tools" style="display:flex; gap:10px; align-items:center;">
                <?php if ($view === 'active'): ?>
                <button type="button" class="batch-promo-btn" id="batchPromoBtn" style="display:none;" onclick="openBatchModal()">
                    <i class="fa-solid fa-tags"></i> Apply Batch Discount (<span id="selectedCount">0</span>)
                </button>
                <?php endif; ?>

                <form method="GET" class="mini-search">
                    <input type="hidden" name="view" value="<?php echo e($view); ?>">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Search <?php echo $view; ?>...">
                </form>
                
                <button class="outline-btn" onclick="openBannerModal()"><i class="fa-solid fa-pen-to-square"></i> Edit Banner</button>
                <button class="primary-btn" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Add Product</button>
            </div>
        </section>

        <?php if(isset($_GET['success'])){ ?>
            <div class="alert success"><?php echo e($_GET['success']); ?></div>
        <?php } ?>
        <?php if(isset($_GET['error'])){ ?>
            <div class="alert error"><?php echo e($_GET['error']); ?></div>
        <?php } ?>

        <div class="product-tabs">
            <a href="?view=active" class="product-tab <?php echo $view === 'active' ? 'active' : ''; ?>">Active Products</a>
            <a href="?view=archived" class="product-tab <?php echo $view === 'archived' ? 'active' : ''; ?>">Archived Products</a>
        </div>

        <section class="panel product-panel">
            <div class="table-responsive">
                <table>
                    <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;">
                            <input type="checkbox" id="selectAllProducts" onclick="toggleSelectAllProducts(this)">
                        </th>
                        <th>Product Name</th>
                        <th>Brand</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Availability</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if($products && $products->num_rows > 0){ ?>
                        <?php while($row = $products->fetch_assoc()){
                            $productJson = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr style="<?php echo $view === 'archived' ? 'opacity: 0.7; background: #f8fafc;' : ''; ?>">
                            <td style="text-align: center;">
                                <input type="checkbox" class="product-select-cb" value="<?php echo (int)$row['id']; ?>" onchange="updateBatchButton()">
                            </td>
                            <td>
                                <strong><?php echo e($row['IndoorUnitModel'] ?: $row['SeriesName']); ?></strong>
                                <?php if(isset($row['is_promo']) && $row['is_promo'] == 1): ?>
                                    <span style="background: #d31b27; color: white; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 5px;">PROMO (<?php echo (int)($row['discount_percent'] ?? 0); ?>% OFF)</span>
                                <?php endif; ?>
                                <br><small><?php echo e($row['SeriesName']); ?></small>
                            </td>
                            <td><?php echo e($row['brand'] ?? ''); ?></td>
                            <td><?php echo e($row['midea_type'] ?? ''); ?></td>
                            <td>₱<?php echo number_format((float)$row['SRP_PHP'], 2); ?></td>
                            <td><?php echo isset($row['stock_quantity']) ? (int)$row['stock_quantity'] : 0; ?></td>
                            <td><span class="badge <?php echo badgeClass($row['stock_status'] ?? 'Available'); ?>"><?php echo e($row['stock_status'] ?? 'Available'); ?></span></td>
                            <td class="actions">
                                
                                <?php if ($view === 'active'): ?>
                                    <button type="button" onclick="openEditModal(this)" data-product="<?php echo $productJson; ?>"><i class="fa-regular fa-pen-to-square"></i></button>
                                    
                                    <?php 
                                        $statusCheck = strtolower(trim($row['stock_status'] ?? ''));
                                        $isAvailable = ($statusCheck === 'available') ? 'true' : 'false';
                                    ?>
                                    <button type="button" title="Archive Product" style="color: #ea580c;" onclick="openArchiveModal(<?php echo (int)$row['id']; ?>, <?php echo $isAvailable; ?>)">
                                        <i class="fa-solid fa-box-archive"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="button" title="Restore Product" style="color: #16a34a;" onclick="openRestoreModal(<?php echo (int)$row['id']; ?>)">
                                        <i class="fa-solid fa-trash-arrow-up"></i>
                                    </button>
                                <?php endif; ?>

                            </td>
                        </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr><td colspan="8" class="empty-row">No <?php echo $view; ?> products found.</td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- ARCHIVE / RESTORE CONFIRMATION MODAL -->
<div class="modal" id="archiveConfirmModal">
    <div class="modal-card confirm-modal-card">
        <button type="button" class="modal-close" onclick="closeArchiveConfirmModal()"><i class="fa-solid fa-xmark"></i></button>
        <div class="confirm-icon-wrap" id="archiveConfirmIcon">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <h2>Please Confirm</h2>
        <p id="archiveConfirmMessage">Are you sure you want to archive this product? It will be hidden from customers.</p>
        <form method="POST" action="admin_products.php">
            <input type="hidden" name="archive_action" id="archiveFormAction" value="archive">
            <input type="hidden" name="product_id" id="archiveProductId" value="">
            <div class="confirm-modal-actions">
                <button type="button" class="confirm-cancel-btn" onclick="closeArchiveConfirmModal()">Cancel</button>
                <button type="submit" class="confirm-proceed-btn" id="archiveConfirmSubmitBtn">Yes, Proceed</button>
            </div>
        </form>
    </div>
</div>

<!-- SINGLE PRODUCT EDIT/ADD MODAL -->
<div class="modal" id="productModal">
    <div class="modal-card" style="max-height: 90vh; overflow-y: auto;">
        <button type="button" class="modal-close" onclick="closeProductModal()"><i class="fa-solid fa-xmark"></i></button>
        <h2 id="modalTitle">Add Product</h2>
        <p id="modalSubtitle">Add a new product to your inventory.</p>

        <!-- UPDATED FORM ONSUBMIT HANDLER -->
        <form method="POST" action="actions/product_action.php" enctype="multipart/form-data" class="product-form" onsubmit="return requireOwnerPassword('owner_password', 'is_promo')">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="productId">
            
            <input type="hidden" name="current_image" id="currentImage">
            <input type="hidden" name="current_image2" id="currentImage2">
            <input type="hidden" name="current_image3" id="currentImage3">
            <input type="hidden" name="current_image4" id="currentImage4">
            
            <input type="hidden" name="remove_image" id="remove_image" value="0">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Product / Series Name</label>
                    <input type="text" name="series" id="series" required>
                </div>
                <div class="form-group">
                    <label>Brand</label>
                    <select name="brand" id="brand" required>
                        <option value="">Select brand</option>
                        <option value="Midea">Midea</option>
                        <option value="Daikin">Daikin</option>
                        <option value="Carrier">Carrier</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Category / Type</label>
                    <select name="midea_type" id="midea_type" required>
                        <option value="">Select category</option>
                        <option value="Split">Split Type</option>
                        <option value="Window">Window Type</option>
                        <option value="Portable">Portable</option>
                        <option value="Floor Standing">Floor Standing</option>
                        <option value="Cassette">Cassette</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Capacity HP</label>
                    <input type="number" step="0.1" name="hp" id="hp" required>
                </div>
                <div class="form-group">
                    <label>Indoor Model</label>
                    <input type="text" name="indoor" id="indoor" required>
                </div>
                <div class="form-group">
                    <label>Outdoor Model</label>
                    <input type="text" name="outdoor" id="outdoor">
                </div>
                <div class="form-group price-input">
                    <label>Base Price (SRP)</label>
                    <div><span>₱</span><input type="number" step="0.01" name="price" id="price" required></div>
                </div>
                <div class="form-group">
                    <label>Stock Quantity</label>
                    <input type="number" name="stock_quantity" id="stock_quantity" min="0" value="0" required>
                </div>
                <div class="form-group">
                    <label>Availability Status</label>
                    <select name="stock_status" id="stock_status" required>
                        <option value="Available">Available</option>
                        <option value="Limited Stock">Limited Stock</option>
                        <option value="Out of Stock">Out of Stock</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Inverter Type</label>
                    <select name="type" id="type" required>
                        <option value="0">Inverter</option>
                        <option value="1">Non-Inverter</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Promo Status</label>
                    <select name="is_promo" id="is_promo" required>
                        <option value="0">Standard Product</option>
                        <option value="1">Discounted Product</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Discount Percentage (0-100)</label>
                    <input type="number" name="discount_percent" id="discount_percent" min="0" max="100" value="0">
                    <small id="computed_price_preview" style="color: #d31b27; font-weight: 700; display: block; margin-top: 5px;"></small>
                </div>

                <div class="form-group full">
                    <label style="color:#d31b27;"><i class="fa-regular fa-clock"></i> Promo End Date & Time</label>
                    <input type="datetime-local" name="promo_end_date" id="promo_end_date" style="border: 1px solid #d31b27;">
                    <small>When this exact time passes, the product will automatically revert to its normal price.</small>
                </div>
                
                <!-- NEW OWNER PASSWORD FIELD FOR SINGLE PRODUCT -->
                <div class="form-group full" id="auth_password_group" style="opacity: 0.4;">
                    <label style="color:#d31b27;"><i class="fa-solid fa-lock"></i> Owner Authorization Passcode</label>
                    <input type="password" name="owner_password" id="owner_password" placeholder="Enter owner passcode to authorize discount" style="border: 1px solid #d31b27;">
                </div>

                <div class="form-group">
                    <label>Cooling Capacity</label>
                    <input type="text" name="cooling_capacity" id="cooling_capacity" placeholder="e.g. 5000 kJ/h">
                </div>
                <div class="form-group">
                    <label>Warranty</label>
                    <input type="text" name="warranty" id="warranty" placeholder="e.g. 1 Year">
                </div>
                <div class="form-group full">
                    <label>Specs</label>
                    <textarea name="features" id="features" placeholder="Enter product features..."></textarea>
                </div>
                <div class="form-group full">
                    <label>Features</label>
                    <textarea name="durability" id="durability" placeholder="Enter durability details..."></textarea>
                </div>
                <div class="form-group full">
                    <label>What's in The box</label>
                    <textarea name="description" id="description" placeholder="Enter product description optional"></textarea>
                </div>

                <div class="form-group full">
                    <label>Primary Product Image</label>
                    <div class="preview-container" id="preview-wrapper" style="display:none;">
                        <img id="preview-img" src="" alt="Preview">
                        <button type="button" class="remove-img-btn" onclick="removeImage()">&times;</button>
                    </div>
                    <input type="file" name="image" id="image" accept="image/*" onchange="previewImage(this)">
                </div>
                
                <div class="form-group full">
                    <label>Product Image 2 (Optional)</label>
                    <input type="file" name="image2" accept="image/*">
                </div>
                
                <div class="form-group full">
                    <label>Product Image 3 (Optional)</label>
                    <input type="file" name="image3" accept="image/*">
                </div>
                
                <div class="form-group full">
                    <label>Product Image 4 (Optional)</label>
                    <input type="file" name="image4" accept="image/*">
                </div>

            </div>

            <div class="modal-actions">
                <button type="button" class="outline-btn" onclick="closeProductModal()">Cancel</button>
                <button type="submit" class="primary-btn" id="submitBtn">Save Product</button>
            </div>
        </form>
    </div>
</div>

<!-- BATCH DISCOUNT MODAL -->
<div class="modal" id="batchModal">
    <div class="modal-card" style="max-width: 500px;">
        <button type="button" class="modal-close" onclick="closeBatchModal()"><i class="fa-solid fa-xmark"></i></button>
        <h2>Apply Batch Discount</h2>
        <p>Set a discount percentage and promo expiration date for all <strong id="batchCountDisplay">0</strong> selected products simultaneously.</p>
        
        <!-- UPDATED FORM ONSUBMIT HANDLER -->
        <form method="POST" action="actions/product_action.php" style="margin-top: 15px;" onsubmit="return requireOwnerPassword('batch_owner_password', 'batch_is_promo')">
            <input type="hidden" name="action" value="batch_discount">
            <div id="batchProductInputsContainer"></div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Promo Status</label>
                <select name="is_promo" id="batch_is_promo" onchange="toggleBatchPromoFields()" required>
                    <option value="1">Discounted Product (Activate Promo)</option>
                    <option value="0">Standard Product (Remove Promo)</option>
                </select>
            </div>
            <div class="form-group" id="batch_discount_group" style="margin-bottom: 15px;">
                <label>Discount Percentage (0-100)</label>
                <input type="number" name="discount_percent" id="batch_discount_percent" min="0" max="100" value="10" required>
            </div>
            <div class="form-group" id="batch_date_group" style="margin-bottom: 20px;">
                <label style="color:#d31b27;"><i class="fa-regular fa-clock"></i> Promo End Date & Time</label>
                <input type="datetime-local" name="promo_end_date" id="batch_promo_end_date" required style="border: 1px solid #d31b27;">
            </div>
            
            <!-- NEW OWNER PASSWORD FIELD FOR BATCH PROMO -->
            <div class="form-group" id="batch_auth_password_group" style="margin-bottom: 20px;">
                <label style="color:#d31b27;"><i class="fa-solid fa-lock"></i> Owner Authorization Passcode</label>
                <input type="password" name="owner_password" id="batch_owner_password" required placeholder="Enter owner passcode to authorize discount" style="border: 1px solid #d31b27;">
            </div>
            
            <div class="modal-actions">
                <button type="button" class="outline-btn" onclick="closeBatchModal()">Cancel</button>
                <button type="submit" class="primary-btn" style="background:#d31b27;">Apply Discount to All Selected</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT BANNER MODAL -->
<div class="modal" id="bannerModal">
    <div class="modal-card" style="max-width: 400px;">
        <button type="button" class="modal-close" onclick="closeBannerModal()"><i class="fa-solid fa-xmark"></i></button>
        <h2>Edit Promo Banner</h2>
        <p>Update the text shown on the customer homepage.</p>
        <form method="POST" action="admin_products.php" style="margin-top: 15px;">
            <input type="hidden" name="update_banner" value="1">
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Banner Title</label>
                <textarea name="promo_title" rows="2" required><?php echo htmlspecialchars($promo_title); ?></textarea>
            </div>
            <div class="form-group" style="margin-bottom: 20px;">
                <label>Banner Subtitle</label>
                <input type="text" name="promo_subtitle" required value="<?php echo htmlspecialchars($promo_subtitle); ?>">
            </div>
            <div class="modal-actions">
                <button type="button" class="outline-btn" onclick="closeBannerModal()">Cancel</button>
                <button type="submit" class="primary-btn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- INJECT GLOBAL MODALS -->
<?php include 'includes/global_modals.php'; ?>

<script>
// ==========================================
// OWNER PASSWORD UX — client-side only shows a warning if field is empty.
// The real passcode check happens server-side in product_action.php.
// The secret is NEVER sent to the browser.
// ==========================================
function requireOwnerPassword(passwordId, promoSelectId) {
    const isPromo = document.getElementById(promoSelectId).value;
    if (isPromo === "1") {
        const pwd = document.getElementById(passwordId).value.trim();
        if (!pwd) {
            alert("Please enter the Owner Authorization Passcode before activating a promo.");
            document.getElementById(passwordId).focus();
            return false;
        }
    }
    return true;
}

// ==========================================
// AUTO-UPDATE STOCK STATUS BASED ON QUANTITY
// ==========================================
document.getElementById('stock_quantity').addEventListener('input', function() {
    let qty = parseInt(this.value) || 0;
    let statusSelect = document.getElementById('stock_status');
    
    if (qty >= 4) {
        statusSelect.value = 'Available';
    } else if (qty >= 1 && qty <= 3) {
        statusSelect.value = 'Limited Stock';
    } else if (qty <= 0) {
        statusSelect.value = 'Out of Stock';
    }
});

// ==========================================
// IMAGE PREVIEW & REMOVAL LOGIC
// ==========================================
function previewImage(input) {
    const wrapper = document.getElementById('preview-wrapper');
    const img = document.getElementById('preview-img');
    const removeFlag = document.getElementById('remove_image');

    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            wrapper.style.display = 'inline-block';
            removeFlag.value = "0"; 
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function removeImage() {
    document.getElementById('preview-wrapper').style.display = 'none';
    document.getElementById('preview-img').src = '';
    document.getElementById('image').value = ''; 
    document.getElementById('remove_image').value = "1"; 
    document.getElementById('currentImage').value = ''; 
}

function setupPreview(imgName) {
    const wrapper = document.getElementById('preview-wrapper');
    const img = document.getElementById('preview-img');
    const removeFlag = document.getElementById('remove_image');
    
    document.getElementById('image').value = '';
    removeFlag.value = "0";
    
    if (imgName) {
        img.src = '../images/products/' + imgName;
        wrapper.style.display = 'inline-block';
    } else {
        img.src = '';
        wrapper.style.display = 'none';
    }
}

// ==========================================
// PROMO DYNAMIC FIELD TOGGLING
// ==========================================
function toggleBatchPromoFields() {
    const isPromo = document.getElementById('batch_is_promo').value;
    const discountInput = document.getElementById('batch_discount_percent');
    const dateInput = document.getElementById('batch_promo_end_date');
    const authInput = document.getElementById('batch_owner_password');
    const authGroup = document.getElementById('batch_auth_password_group');
    
    if (isPromo === "1") {
        discountInput.required = true;
        discountInput.readOnly = false;
        document.getElementById('batch_discount_group').style.opacity = "1";
        dateInput.required = true;
        dateInput.readOnly = false;
        document.getElementById('batch_date_group').style.opacity = "1";
        
        authInput.required = true;
        authInput.readOnly = false;
        authGroup.style.opacity = "1";
    } else {
        discountInput.required = false;
        discountInput.readOnly = true;
        discountInput.value = "0";
        document.getElementById('batch_discount_group').style.opacity = "0.4";
        dateInput.required = false;
        dateInput.readOnly = true;
        dateInput.value = "";
        document.getElementById('batch_date_group').style.opacity = "0.4";
        
        authInput.required = false;
        authInput.readOnly = true;
        authInput.value = "";
        authGroup.style.opacity = "0.4";
    }
}

document.getElementById('is_promo').addEventListener('change', function() {
    const isPromo = this.value;
    const discountInput = document.getElementById('discount_percent');
    const dateInput = document.getElementById('promo_end_date');
    const authInput = document.getElementById('owner_password');
    const authGroup = document.getElementById('auth_password_group');
    
    if (isPromo === "1") {
        discountInput.required = true;
        discountInput.readOnly = false;
        discountInput.parentElement.style.opacity = "1";
        dateInput.required = true;
        dateInput.readOnly = false;
        dateInput.parentElement.style.opacity = "1";
        
        authInput.required = true;
        authInput.readOnly = false;
        authGroup.style.opacity = "1";
    } else {
        discountInput.required = false;
        discountInput.readOnly = true;
        discountInput.value = "0";
        discountInput.parentElement.style.opacity = "0.4";
        dateInput.required = false;
        dateInput.readOnly = true;
        dateInput.value = "";
        dateInput.parentElement.style.opacity = "0.4";
        
        authInput.required = false;
        authInput.readOnly = true;
        authInput.value = "";
        authGroup.style.opacity = "0.4";
    }
    computePreviewPrice();
});

// ==========================================
// MODAL & CHECKBOX LOGIC
// ==========================================
function toggleSelectAllProducts(master) {
    const checkboxes = document.querySelectorAll('.product-select-cb');
    checkboxes.forEach(cb => cb.checked = master.checked);
    updateBatchButton();
}

function updateBatchButton() {
    const selected = document.querySelectorAll('.product-select-cb:checked');
    const btn = document.getElementById('batchPromoBtn');
    if (!btn) return; 
    const countSpan = document.getElementById('selectedCount');
    
    countSpan.textContent = selected.length;
    if (selected.length > 0) {
        btn.style.display = 'inline-flex';
    } else {
        btn.style.display = 'none';
    }
}

function openBatchModal() {
    const selected = document.querySelectorAll('.product-select-cb:checked');
    if (selected.length === 0) return;

    const container = document.getElementById('batchProductInputsContainer');
    container.innerHTML = '';

    selected.forEach(cb => {
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'product_ids[]';
        hiddenInput.value = cb.value;
        container.appendChild(hiddenInput);
    });

    document.getElementById('batchCountDisplay').textContent = selected.length;
    document.getElementById('batch_is_promo').value = "1";
    document.getElementById('batch_discount_percent').value = "10";
    document.getElementById('batch_promo_end_date').value = "";
    document.getElementById('batch_owner_password').value = "";
    toggleBatchPromoFields(); 
    document.getElementById('batchModal').classList.add('show');
}

function closeBatchModal() {
    document.getElementById('batchModal').classList.remove('show');
}

function openBannerModal() {
    document.getElementById('bannerModal').classList.add('show');
}

function closeBannerModal() {
    document.getElementById('bannerModal').classList.remove('show');
}

document.getElementById('price').addEventListener('input', computePreviewPrice);
document.getElementById('discount_percent').addEventListener('input', computePreviewPrice);

function computePreviewPrice() {
    let basePrice = parseFloat(document.getElementById('price').value) || 0;
    let discount = parseInt(document.getElementById('discount_percent').value) || 0;
    let previewEl = document.getElementById('computed_price_preview');
    
    if (discount > 0 && discount <= 100 && basePrice > 0) {
        let finalPrice = basePrice - (basePrice * (discount / 100));
        previewEl.textContent = "Computed Promo Price: ₱" + finalPrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        previewEl.textContent = "";
    }
}

function openAddModal(){
    document.getElementById('modalTitle').textContent = 'Add Product';
    document.getElementById('modalSubtitle').textContent = 'Add a new product to your inventory.';
    document.getElementById('submitBtn').textContent = 'Save Product';
    document.getElementById('formAction').value = 'add';
    document.querySelector('.product-form').reset();
    document.getElementById('productId').value = '';
    
    document.getElementById('currentImage').value = '';
    document.getElementById('currentImage2').value = '';
    document.getElementById('currentImage3').value = '';
    document.getElementById('currentImage4').value = '';
    document.getElementById('owner_password').value = ''; 
    
    setupPreview('');
    
    document.getElementById('is_promo').value = '0'; 
    document.getElementById('is_promo').dispatchEvent(new Event('change'));
    
    document.getElementById('productModal').classList.add('show');
}

function openEditModal(button){
    const p = JSON.parse(button.dataset.product);
    document.getElementById('modalTitle').textContent = 'Edit Product';
    document.getElementById('modalSubtitle').textContent = 'Update product information and availability.';
    document.getElementById('submitBtn').textContent = 'Update Product';
    document.getElementById('formAction').value = 'update';
    document.getElementById('productId').value = p.id || '';
    document.getElementById('series').value = p.SeriesName || '';
    document.getElementById('brand').value = p.brand || '';
    document.getElementById('midea_type').value = p.midea_type || '';
    document.getElementById('hp').value = p.CapacityHP || '';
    document.getElementById('indoor').value = p.IndoorUnitModel || '';
    document.getElementById('outdoor').value = p.OutdoorUnitModel || '';
    document.getElementById('price').value = p.SRP_PHP || '';
    document.getElementById('stock_quantity').value = p.stock_quantity || 0;
    document.getElementById('stock_status').value = p.stock_status || 'Available';
    document.getElementById('type').value = p.is_non_inverter || 0;
    document.getElementById('cooling_capacity').value = p.cooling_capacity || '';
    document.getElementById('warranty').value = p.warranty || '';
    document.getElementById('features').value = p.features || '';
    document.getElementById('durability').value = p.durability || '';
    document.getElementById('description').value = p.description || '';
    
    document.getElementById('currentImage').value = p.image || '';
    document.getElementById('currentImage2').value = p.image2 || '';
    document.getElementById('currentImage3').value = p.image3 || '';
    document.getElementById('currentImage4').value = p.image4 || '';
    document.getElementById('owner_password').value = ''; 

    setupPreview(p.image);
    
    document.getElementById('discount_percent').value = p.discount_percent || '0';
    if (p.promo_end_date && p.promo_end_date !== '0000-00-00 00:00:00') {
        document.getElementById('promo_end_date').value = p.promo_end_date.replace(' ', 'T').substring(0, 16);
    } else {
        document.getElementById('promo_end_date').value = '';
    }
    
    document.getElementById('is_promo').value = p.is_promo || '0'; 
    document.getElementById('is_promo').dispatchEvent(new Event('change'));
    
    document.getElementById('productModal').classList.add('show');
}

function closeProductModal(){
    document.getElementById('productModal').classList.remove('show');
}

// ==========================================
// ARCHIVE & RESTORE MODAL LOGIC
// ==========================================
function openArchiveModal(productId, isAvailable) {
    let msg = "";
    if (isAvailable) {
        msg = "WARNING: This product is still marked as AVAILABLE! Are you sure you want to archive it and hide it from the catalog?";
    } else {
        msg = "Are you sure you want to archive this product? It will be hidden from customers.";
    }

    document.getElementById('archiveFormAction').value = 'archive';
    document.getElementById('archiveProductId').value = productId;
    document.getElementById('archiveConfirmMessage').textContent = msg;

    const iconWrap = document.getElementById('archiveConfirmIcon');
    const submitBtn = document.getElementById('archiveConfirmSubmitBtn');
    iconWrap.classList.remove('restore-icon');
    iconWrap.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
    submitBtn.classList.remove('restore-btn');
    submitBtn.textContent = 'Yes, Proceed';

    document.getElementById('archiveConfirmModal').classList.add('show');
}

function openRestoreModal(productId) {
    document.getElementById('archiveFormAction').value = 'unarchive';
    document.getElementById('archiveProductId').value = productId;
    document.getElementById('archiveConfirmMessage').textContent = "Are you sure you want to restore this product? It will become visible to customers again.";

    const iconWrap = document.getElementById('archiveConfirmIcon');
    const submitBtn = document.getElementById('archiveConfirmSubmitBtn');
    iconWrap.classList.add('restore-icon');
    iconWrap.innerHTML = '<i class="fa-solid fa-trash-arrow-up"></i>';
    submitBtn.classList.add('restore-btn');
    submitBtn.textContent = 'Yes, Restore';

    document.getElementById('archiveConfirmModal').classList.add('show');
}

function closeArchiveConfirmModal() {
    document.getElementById('archiveConfirmModal').classList.remove('show');
}
</script>
</body>
</html>