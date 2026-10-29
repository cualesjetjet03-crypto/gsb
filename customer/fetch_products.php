<?php
include "db_connect.php";
header('Content-Type: application/json');

// BASE: never show archived products
$conditions = ["is_archived = 0"];

// ── SEARCH ─────────────────────────────────────────────────────────────────
if (!empty($_GET['search'])) {
    $raw = trim($_GET['search']);
    $s   = $conn->real_escape_string($raw);
    // HP-only search (e.g. "1.5", "2hp")
    if (preg_match('/^([0-9]+\.?[0-9]*)\s*(hp)?$/i', $raw, $m) && floatval($m[1]) <= 10) {
        $conditions[] = "CapacityHP = '" . $conn->real_escape_string($m[1]) . "'";
    } else {
        $conditions[] = "(
            IndoorUnitModel   LIKE '%$s%' OR
            SeriesName        LIKE '%$s%' OR
            brand             LIKE '%$s%' OR
            SRP_PHP           LIKE '%$s%' OR
            cooling_capacity  LIKE '%$s%' OR
            warranty          LIKE '%$s%' OR
            IF(is_non_inverter=1,'Non-Inverter','Inverter') LIKE '%$s%'
        )";
    }
}

// ── BRAND ──────────────────────────────────────────────────────────────────
if (!empty($_GET['brand'])) {
    $brands = is_array($_GET['brand']) ? $_GET['brand'] : [$_GET['brand']];
    $bc = array_map(fn($b) => "LOWER(brand)=LOWER('" . $conn->real_escape_string(trim($b)) . "')", $brands);
    $conditions[] = "(" . implode(" OR ", $bc) . ")";
}

// ── DAIKIN SERIES ──────────────────────────────────────────────────────────
if (!empty($_GET['series'])) {
    $series = is_array($_GET['series']) ? $_GET['series'] : [$_GET['series']];
    $sc = [];
    foreach ($series as $sv) {
        $safe = $conn->real_escape_string(trim($sv));
        $sc[] = "SeriesName LIKE '%$safe%'";
    }
    $conditions[] = "(" . implode(" OR ", $sc) . ")";
}

// ── CARRIER SERIES ─────────────────────────────────────────────────────────
if (!empty($_GET['carrier_series'])) {
    $cs = is_array($_GET['carrier_series']) ? $_GET['carrier_series'] : [$_GET['carrier_series']];
    $cc = [];
    foreach ($cs as $cv) {
        $safe = $conn->real_escape_string(trim($cv));
        $cc[] = "SeriesName LIKE '%$safe%'";
    }
    $conditions[] = "brand='Carrier' AND (" . implode(" OR ", $cc) . ")";
}

// ── TYPE (Inverter / Non-Inverter) ─────────────────────────────────────────
if (!empty($_GET['is_non_inverter'])) {
    $types = is_array($_GET['is_non_inverter']) ? $_GET['is_non_inverter'] : [$_GET['is_non_inverter']];
    $tv = array_unique(array_map(fn($v) => (int)$v, $types)); // 0 or 1 only
    $conditions[] = "is_non_inverter IN (" . implode(",", $tv) . ")";
}

// ── MIDEA TYPE ─────────────────────────────────────────────────────────────
if (!empty($_GET['midea_type'])) {
    $mt = is_array($_GET['midea_type']) ? $_GET['midea_type'] : [$_GET['midea_type']];
    $mmap = [];
    foreach ($mt as $m) {
        $t = strtolower(trim($m));
        if (in_array($t, ['split','window','portable'])) {
            $mmap[] = "'" . $conn->real_escape_string($t) . "'";
        }
    }
    if (!empty($mmap)) {
        $conditions[] = "brand='Midea' AND LOWER(midea_type) IN (" . implode(",", $mmap) . ")";
    }
}

// ── HORSEPOWER ─────────────────────────────────────────────────────────────
if (!empty($_GET['hp'])) {
    $hp = is_array($_GET['hp']) ? $_GET['hp'] : [$_GET['hp']];
    // Cast to float then back to string to avoid injection, use ROUND for decimal precision
    $hpv = array_unique(array_map(fn($h) => round((float)$h, 1), $hp));
    $conditions[] = "ROUND(CapacityHP,1) IN (" . implode(",", $hpv) . ")";
}

// ── STOCK STATUS ───────────────────────────────────────────────────────────
if (!empty($_GET['stock_status'])) {
    $ss = is_array($_GET['stock_status']) ? $_GET['stock_status'] : [$_GET['stock_status']];
    $ssv = array_map(fn($s) => "'" . $conn->real_escape_string(trim($s)) . "'", $ss);
    $conditions[] = "stock_status IN (" . implode(",", $ssv) . ")";
}

// ── BUILD SQL ──────────────────────────────────────────────────────────────
$sql = "SELECT * FROM products WHERE " . implode(" AND ", $conditions);

// ── SORT ───────────────────────────────────────────────────────────────────
$sort = $_GET['sort'] ?? '';
if ($sort === 'price_asc')  $sql .= " ORDER BY SRP_PHP ASC";
elseif ($sort === 'price_desc') $sql .= " ORDER BY SRP_PHP DESC";
elseif ($sort === 'name_asc')   $sql .= " ORDER BY SeriesName ASC";
else $sql .= " ORDER BY (stock_status='Out of Stock' OR stock_quantity<=0) ASC, (is_promo=1 AND discount_percent>0 AND promo_end_date>=NOW()) DESC, id DESC";

// ── EXECUTE ────────────────────────────────────────────────────────────────
$result = $conn->query($sql);

if (!$result) {
    error_log("fetch_products error: " . $conn->error . " | SQL: " . $sql);
    echo json_encode(["Products" => [], "error" => "Query failed: " . $conn->error]);
    exit;
}

$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

echo json_encode(["Products" => $products]);
?>
