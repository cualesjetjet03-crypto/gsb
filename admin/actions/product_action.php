<?php
    session_start();

    // Updated path to includes folder
    include "../includes/db_connect.php";

    /* =========================================================
    ADMIN ACCESS CHECK
    ========================================================= */
    if (!isset($_SESSION['admin'])) {
        // Redirect back to root admin folder
        header("Location: ../admin_login.php");
        exit();
    }

    /* =========================================================
    SECURE OWNER PASSCODE CHECK (SERVER-SIDE ONLY)
    Never sent to the browser. Supports both bcrypt hash and
    plain-text (legacy) passcodes during migration.
    ========================================================= */
    $pass_query   = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key = 'owner_passcode' LIMIT 1");
    $stored_passcode = ($pass_query && $pass_query->num_rows > 0)
        ? $pass_query->fetch_assoc()['setting_value']
        : '';

    // If any form is trying to activate a promo (is_promo = 1), verify the submitted password
    if (isset($_POST['is_promo']) && $_POST['is_promo'] == '1') {
        $submitted_passcode = $_POST['owner_password'] ?? '';

        // Accept bcrypt hash OR plain-text (for existing plain-text passcodes in DB)
        $passcode_valid = !empty($stored_passcode) && (
            password_verify($submitted_passcode, $stored_passcode) ||
            hash_equals($stored_passcode, $submitted_passcode)
        );

        if (!$passcode_valid) {
            header("Location: ../admin_products.php?error=" . urlencode("Unauthorized: Incorrect Owner Authorization Passcode."));
            exit();
        }
    }

    /* =========================================================
    GET ACTION
    ========================================================= */
    $action = $_POST['action'] ?? '';

    /* =========================================================
    BATCH DISCOUNT ACTION HANDLER
    ========================================================= */
    if ($action === 'batch_discount') {
        $product_ids = $_POST['product_ids'] ?? [];
        $is_promo = isset($_POST['is_promo']) ? (int)$_POST['is_promo'] : 0;
        $discount_percent = isset($_POST['discount_percent']) ? (int)$_POST['discount_percent'] : 0;
        $promo_end_date = !empty($_POST['promo_end_date']) ? $_POST['promo_end_date'] : null;

        if (!empty($product_ids) && is_array($product_ids)) {
            // Build parameterized placeholders (?, ?, ?, ...) for SQL IN clause
            $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
            $types = "iis" . str_repeat('i', count($product_ids));
            
            $sql = "UPDATE products SET is_promo = ?, discount_percent = ?, promo_end_date = ? WHERE id IN ($placeholders)";
            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                header("Location: ../admin_products.php?error=" . urlencode("Database error: " . $conn->error));
                exit();
            }

            // Combine parameters: is_promo, discount_percent, promo_end_date, plus all selected product IDs
            $params = array_merge([$is_promo, $discount_percent, $promo_end_date], array_map('intval', $product_ids));
            $stmt->bind_param($types, ...$params);

            if ($stmt->execute()) {
                $count = count($product_ids);
                header("Location: ../admin_products.php?success=" . urlencode("Batch discount successfully applied to {$count} products."));
            } else {
                header("Location: ../admin_products.php?error=" . urlencode("Failed to update products."));
            }
        } else {
            header("Location: ../admin_products.php?error=" . urlencode("No products were selected for batch update."));
        }
        exit();
    }

    /* =========================================================
    ADD / UPDATE PRODUCT
    ========================================================= */
    if ($action === 'add' || $action === 'update') {

        /* -----------------------------------------------------
        BASIC PRODUCT INFORMATION
        ----------------------------------------------------- */
        $id = $_POST['id'] ?? null;

        $series = trim($_POST['series'] ?? '');
        $brand = trim($_POST['brand'] ?? '');
        $midea_type = trim($_POST['midea_type'] ?? '');

        $hp = $_POST['hp'] ?? 0;

        $indoor = trim($_POST['indoor'] ?? '');
        $outdoor = trim($_POST['outdoor'] ?? '');

        $price = $_POST['price'] ?? 0;

        $stock_quantity = $_POST['stock_quantity'] ?? 0;
        $stock_status = $_POST['stock_status'] ?? 'Available';
        $type = $_POST['type'] ?? 0;

        $is_promo = isset($_POST['is_promo']) ? (int)$_POST['is_promo'] : 0;
        $discount_percent = isset($_POST['discount_percent']) ? (int)$_POST['discount_percent'] : 0;
        $promo_end_date = !empty($_POST['promo_end_date']) ? $_POST['promo_end_date'] : null;

        /* -----------------------------------------------------
        CUSTOM PRODUCT PROPERTIES & PROMO
        ----------------------------------------------------- */
        $cooling_capacity = trim($_POST['cooling_capacity'] ?? '');
        $warranty = trim($_POST['warranty'] ?? '');
        $features = trim($_POST['features'] ?? '');
        $durability = trim($_POST['durability'] ?? '');
        $description = trim($_POST['description'] ?? '');

        /* -----------------------------------------------------
        IMAGE HANDLING (NOW SUPPORTS 4 IMAGES)
        ----------------------------------------------------- */
        $imageName = trim($_POST['current_image'] ?? '');
        $imageName2 = trim($_POST['current_image2'] ?? '');
        $imageName3 = trim($_POST['current_image3'] ?? '');
        $imageName4 = trim($_POST['current_image4'] ?? '');

        $uploadFileDir = __DIR__ . '/../../images/products/';

        if (!is_dir($uploadFileDir)) {
            if (!mkdir($uploadFileDir, 0755, true)) {
                die("Error: Unable to create the products image folder.");
            }
        }

        // Map the file inputs to their respective variable references
        $imageFields = [
            'image' => &$imageName,
            'image2' => &$imageName2,
            'image3' => &$imageName3,
            'image4' => &$imageName4
        ];

        foreach ($imageFields as $field => &$currentNameVar) {
            if (isset($_FILES[$field]) && $_FILES[$field]['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                    die("Error: There was a problem uploading {$field}.");
                }

                $fileTmpPath = $_FILES[$field]['tmp_name'];
                $fileName    = $_FILES[$field]['name'];
                $fileSize    = $_FILES[$field]['size'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                // ── Size limit: 5 MB ──────────────────────────────────────
                if ($fileSize > 5 * 1024 * 1024) {
                    die("Error: {$field} exceeds the 5 MB size limit.");
                }

                // ── Extension whitelist ───────────────────────────────────
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                if (!in_array($fileExtension, $allowedExtensions, true)) {
                    die("Error: Invalid image type for {$field}. Only JPG, JPEG, PNG, and WEBP are allowed.");
                }

                // ── Real MIME check via finfo (prevents polyglot/PHP disguised as image) ──
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($fileTmpPath);
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
                if (!in_array($mimeType, $allowedMimes, true)) {
                    die("Error: {$field} failed MIME validation. Upload a genuine image file.");
                }

                // ── getimagesize as secondary sanity check ────────────────
                $imageInfo = @getimagesize($fileTmpPath);
                if ($imageInfo === false) {
                    die("Error: The uploaded file for {$field} is not a valid image.");
                }

                // ── Random filename — never use original name ─────────────
                $newFileName = bin2hex(random_bytes(16)) . '.' . $fileExtension;
                $dest_path   = $uploadFileDir . $newFileName;

                if (move_uploaded_file($fileTmpPath, $dest_path)) {
                    $currentNameVar = $newFileName;
                } else {
                    die("Error: Failed to move uploaded file for {$field}. Check folder permissions.");
                }
            }
        }

        /* =====================================================
        ADD PRODUCT
        ===================================================== */
        if ($action === 'add') {
            $stmt = $conn->prepare("
            INSERT INTO products
            (
                SeriesName, brand, midea_type, CapacityHP, IndoorUnitModel, OutdoorUnitModel,
                SRP_PHP, stock_quantity, stock_status, is_non_inverter, cooling_capacity,
                warranty, features, durability, description, image, image2, image3, image4, is_promo, discount_percent, promo_end_date
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");

        if (!$stmt) die("Prepare failed: (" . $conn->errno . ") " . $conn->error);

        $stmt->bind_param(
            "sssdssdisisssssssssiis",
            $series, $brand, $midea_type, $hp, $indoor, $outdoor,
            $price, $stock_quantity, $stock_status, $type, $cooling_capacity,
            $warranty, $features, $durability, $description, 
            $imageName, $imageName2, $imageName3, $imageName4, 
            $is_promo, $discount_percent, $promo_end_date
        );

            if ($stmt->execute()) {
                $stmt->close();
                header("Location: ../admin_products.php?success=" . urlencode("Product added successfully"));
                exit();
            } else {
                $stmt->close();
                header("Location: ../admin_products.php?error=" . urlencode("Failed to add product: " . $conn->error));
                exit();
            }
        }

        /* =====================================================
        UPDATE PRODUCT
        ===================================================== */
        if ($action === 'update' && !empty($id)) {
            $stmt = $conn->prepare("
            UPDATE products SET
                SeriesName = ?, brand = ?, midea_type = ?, CapacityHP = ?,
                IndoorUnitModel = ?, OutdoorUnitModel = ?, SRP_PHP = ?,
                stock_quantity = ?, stock_status = ?, is_non_inverter = ?,
                cooling_capacity = ?, warranty = ?, features = ?,
                durability = ?, description = ?, image = ?, image2 = ?, image3 = ?, image4 = ?, is_promo = ?, discount_percent = ?, promo_end_date = ?
            WHERE id = ?
        ");

        if (!$stmt) die("Prepare failed: (" . $conn->errno . ") " . $conn->error);

        $stmt->bind_param(
            "sssdssdisisssssssssiisi",
            $series, $brand, $midea_type, $hp, $indoor, $outdoor,
            $price, $stock_quantity, $stock_status, $type, $cooling_capacity,
            $warranty, $features, $durability, $description, 
            $imageName, $imageName2, $imageName3, $imageName4, 
            $is_promo, $discount_percent, $promo_end_date, $id
        );

            if ($stmt->execute()) {
                $stmt->close();
                header("Location: ../admin_products.php?success=" . urlencode("Product updated successfully"));
                exit();
            } else {
                $errorMessage = $stmt->error;
                $stmt->close();
                header("Location: ../admin_products.php?error=" . urlencode("Failed to update product: " . $errorMessage));
                exit();
            }
        }
    }

    /* =========================================================
    SOFT DELETE: ARCHIVE PRODUCT
    ========================================================= */
    if ($action === 'archive') {
        $id = $_POST['id'] ?? null;
        if (!empty($id)) {
            $stmt = $conn->prepare("UPDATE products SET is_archived = 1 WHERE id = ?");
            if (!$stmt) die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
            
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $stmt->close();
                header("Location: ../admin_products.php?success=" . urlencode("Product successfully archived."));
                exit();
            } else {
                $errorMessage = $stmt->error;
                $stmt->close();
                header("Location: ../admin_products.php?error=" . urlencode("Failed to archive product: " . $errorMessage));
                exit();
            }
        }
        header("Location: ../admin_products.php?error=" . urlencode("Invalid product ID"));
        exit();
    }

    /* =========================================================
    RESTORE PRODUCT (UNARCHIVE)
    ========================================================= */
    if ($action === 'unarchive') {
        $id = $_POST['id'] ?? null;
        if (!empty($id)) {
            $stmt = $conn->prepare("UPDATE products SET is_archived = 0 WHERE id = ?");
            if (!$stmt) die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
            
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $stmt->close();
                header("Location: ../admin_products.php?view=archived&success=" . urlencode("Product successfully restored."));
                exit();
            } else {
                $errorMessage = $stmt->error;
                $stmt->close();
                header("Location: ../admin_products.php?view=archived&error=" . urlencode("Failed to restore product: " . $errorMessage));
                exit();
            }
        }
        header("Location: ../admin_products.php?view=archived&error=" . urlencode("Invalid product ID"));
        exit();
    }

    header("Location: ../admin_products.php?error=" . urlencode("Invalid action"));
    exit();
?>