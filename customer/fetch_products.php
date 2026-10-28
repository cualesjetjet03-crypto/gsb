<?php
include "db_connect.php";

header('Content-Type: application/json');

// ==========================================
// 1. BASE CONDITION: EXCLUDE ARCHIVED PRODUCTS
// ==========================================
// This ensures that no matter what filters the customer clicks,
// archived items will never show up on the frontend.
$conditions = ["is_archived = 0"];

// ==========================================
// SMART SEARCH LOGIC
// ==========================================
if (!empty($_GET['search'])) {
    $raw_search = trim($_GET['search']);
    $search = $conn->real_escape_string($raw_search);
    
    // SMART HP DETECTION: 
    // If they type "2.5", "1.0", or "2.5 hp" (any number under 10), target ONLY the CapacityHP column.
    // This stops "2.5" from accidentally matching the "2.5 kW" cooling capacity of a 1.0HP unit.
    if (preg_match('/^([0-9\.]+)\s*(hp)?$/i', $raw_search, $matches) && floatval($matches[1]) <= 10) {
        $hp_val = $conn->real_escape_string($matches[1]);
        $conditions[] = "CapacityHP = '$hp_val'";
    } else {
        // Normal broad search for Models, Brands, Prices (e.g. 37195), etc.
        $conditions[] = "(
            IndoorUnitModel LIKE '%$search%' OR 
            SeriesName LIKE '%$search%' OR 
            CapacityHP LIKE '%$search%' OR 
            SRP_PHP LIKE '%$search%' OR 
            cooling_capacity LIKE '%$search%' OR 
            warranty LIKE '%$search%' OR 
            brand LIKE '%$search%' OR
            IF(is_non_inverter = 1, 'Non-Inverter', 'Inverter') LIKE '%$search%'
        )";
    }
}

// ✅ DAIKIN SERIES FILTER
if (!empty($_GET['series'])) {
    $series = is_array($_GET['series']) ? $_GET['series'] : [$_GET['series']];
    $seriesConditions = [];
    
    foreach ($series as $s) {
        if (stripos($s, 'Cooling King') !== false) {
            $seriesConditions[] = "SeriesName LIKE '%Cooling King%'";
        } else {
            $safe_s = $conn->real_escape_string($s);
            $seriesConditions[] = "SeriesName LIKE '%$safe_s%'";
        }
    }
    
    $conditions[] = "(" . implode(" OR ", $seriesConditions) . ")";
}

// ✅ CARRIER SERIES FILTER (AURA & NEXUS)
if (!empty($_GET['carrier_series'])) {
    $c_series = is_array($_GET['carrier_series']) ? $_GET['carrier_series'] : [$_GET['carrier_series']];
    $carrierConditions = [];
    
    foreach ($c_series as $c) {
        $safe_c = $conn->real_escape_string($c);
        $carrierConditions[] = "SeriesName LIKE '%$safe_c%'";
    }
    
    // Ensure it only searches Carrier products
    $conditions[] = "(" . implode(" OR ", $carrierConditions) . ") AND brand = 'Carrier'";
}

// INVERTER FILTER — cast each value to int (0 or 1) to prevent injection
if (!empty($_GET['is_non_inverter'])) {
    $types = is_array($_GET['is_non_inverter']) ? $_GET['is_non_inverter'] : [$_GET['is_non_inverter']];
    $safe_types = array_unique(array_map(fn($v) => (int)$v === 1 ? 1 : 0, $types));
    $conditions[] = "is_non_inverter IN (" . implode(",", $safe_types) . ")";
}

// BRAND FILTER — case-insensitive
if (!empty($_GET['brand'])) {
    $brands = is_array($_GET['brand']) ? $_GET['brand'] : [$_GET['brand']];
    $brand_conditions = [];
    foreach ($brands as $b) {
        $safe_b = $conn->real_escape_string(trim($b));
        $brand_conditions[] = "LOWER(brand) = LOWER('$safe_b')";
    }
    $conditions[] = "(" . implode(" OR ", $brand_conditions) . ")";
}

// MIDEA TYPE FILTER
if (!empty($_GET['midea_type'])) {
    $types = is_array($_GET['midea_type']) ? $_GET['midea_type'] : [$_GET['midea_type']];
    $mapped = [];

    foreach ($types as $type) {
        $t = strtolower(trim($type));
        if ($t === "split") $mapped[] = "'split'";
        if ($t === "window") $mapped[] = "'window'";
        if ($t === "portable") $mapped[] = "'portable'";
    }

    if (!empty($mapped)) {
        $conditions[] = "LOWER(midea_type) IN (" . implode(",", $mapped) . ") AND brand = 'Midea'";
    }
}

// HP FILTER — use ROUND to normalize decimal precision (e.g., 1.0 == 1.00)
if (!empty($_GET['hp'])) {
    $hp_vals = is_array($_GET['hp']) ? $_GET['hp'] : [$_GET['hp']];
    $hp_floats = array_unique(array_map(fn($h) => (float)$h, $hp_vals));
    $hp_list = implode(",", $hp_floats);
    $conditions[] = "ROUND(CapacityHP, 1) IN ($hp_list)";
}

// STOCK STATUS FILTER
if (!empty($_GET['stock_status'])) {
    $stocks = is_array($_GET['stock_status']) ? $_GET['stock_status'] : [$_GET['stock_status']];
    $stocks = array_map(fn($s) => "'" . $conn->real_escape_string($s) . "'", $stocks);
    $conditions[] = "stock_status IN (" . implode(",", $stocks) . ")";
}

// BUILD QUERY
$sql = "SELECT * FROM products";

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

// SORT
if (!empty($_GET['sort'])) {
    if ($_GET['sort'] == "price_asc") {
        $sql .= " ORDER BY SRP_PHP ASC";
    } elseif ($_GET['sort'] == "price_desc") {
        $sql .= " ORDER BY SRP_PHP DESC";
    } elseif ($_GET['sort'] == "name_asc") {
        $sql .= " ORDER BY SeriesName ASC";
    }
}

// EXECUTE
$result = $conn->query($sql);

if (!$result) {
    error_log("fetch_products query error: " . $conn->error);
    echo json_encode(["Products" => [], "error" => "Query failed."]);
    exit;
}

// FETCH
$products = [];

while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

// RESPONSE
echo json_encode(["Products" => $products]);
?>