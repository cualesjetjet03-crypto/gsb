<?php
session_start(); // Start session to check login status
$is_logged_in = isset($_SESSION['user_id']); // Check if the user is logged in
$brand = isset($_GET['brand']) ? htmlspecialchars($_GET['brand']) : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?php echo ucfirst($brand); ?> Products</title>

<link rel="stylesheet" href="css/brand.css">
<link rel="stylesheet" href="css/navbar.css">
<link rel="icon" type="image/png" href="favicon.png">

<script>
// Hamburger menu toggle logic
function toggleMenu(){
    let menu = document.getElementById("dropdownMenu");
    let icon = document.getElementById("menuIcon");
    if(menu && icon) {
        menu.classList.toggle("show");
        icon.classList.toggle("active");
    }
}
</script>
</head>

<body>

<?php include 'navbar.php'; ?>

<!-- BRAND TITLE -->
<section class="brand-header">
    <h1><?php echo strtoupper($brand); ?> AIRCONDITIONERS</h1>
</section>

<!-- PRODUCT GRID -->
<section class="product-grid">

    <?php if(strtolower($brand) == "daikin"): ?>
    <div class="product-grid">
        <div class="product-card" onclick="goToSeries('Cooling King Series Premium')">
            <div class="product-img-wrapper">
                <img src="images/products/daikin/daikin1.png">
            </div>
            <h3>COOLING KING SERIES</h3>
            <p>Non Inverter Split Type</p>
        </div>

        <div class="product-card" onclick="goToSeries('D-Smart Series')">
            <div class="product-img-wrapper">
                <img src="images/products/daikin/daikin3.png">
            </div>
            <h3>D-SMART SERIES</h3>
            <p>Inverter Split Type</p>
        </div>

        <div class="product-card" onclick="goToSeries('D-Smart Queen Series')">
            <div class="product-img-wrapper">
                <img src="images/products/daikin/daikin2.png">
            </div>
            <h3>D-SMART QUEEN SERIES</h3>
            <p>Inverter Split Type</p>

        </div>

        <div class="product-card" onclick="goToSeries('D-Smart King Series')">
            <div class="product-img-wrapper">
                <img src="images/products/daikin/daikin4.png">
            </div>
            <h3>D-SMART KING SERIES</h3>
            <p>Inverter Split Type</p>
        </div>
    </div>

    <?php elseif(strtolower($brand) == "midea"): ?>
    <div class="product-grid">
        <div class="product-card" onclick="goToMideaType('Window')">
            <div class="product-img-wrapper">
                <img src="images/products/midea/windowtype.png">
            </div>
            <h3>WINDOW TYPE</h3>
            <p>Manual & Inverter Window Units</p>
        </div>

        <div class="product-card" onclick="goToMideaType('Portable')">
            <div class="product-img-wrapper">
                <img src="images/products/midea/portable.png">
            </div>
            <h3>PORTABLE</h3>
            <p>Easy-to-move Air Conditioners</p>
        </div>

        <div class="product-card" onclick="goToMideaType('Split')">
            <div class="product-img-wrapper">
                <img src="images/products/midea/splittype.png">
            </div>
            <h3>SPLIT TYPE</h3>
            <p>Wall Mounted Inverter Systems</p>
        </div>
    </div>

    <?php elseif(strtolower($brand) == "carrier"): ?>
    <style>
    .product-img-wrapper {
        width: 90%;
        height: 250px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        padding: 10px;
    }
    .product-img-wrapper img {
        max-width: 120%;
        max-height: 100%;
        object-fit: contain;
    }
    </style>

    <div class="product-grid">
        <div class="product-card" onclick="goToCarrier()">
            <div class="product-img-wrapper">
                <img src="images/products/carrier/carrier1.png">
            </div>
            <h3>NEXUS</h3>
            <p>Inverter Split Type</p>
        </div>

        <div class="product-card" onclick="goToCarrier()">
            <div class="product-img-wrapper">
                <img src="images/products/carrier/carrier2.jpg">
            </div>
            <h3>AURA</h3>
            <p>Inverter Split Type</p>
        </div>
    </div>

    <?php else: ?>
        <h2>No products available.</h2>
    <?php endif; ?>

</section>

<!-- UPDATED JS TO POINT TO PRODUCTS.PHP WITH FILTERS -->
<script>
const currentBrand = "<?php echo htmlspecialchars($brand); ?>";

function goToSeries(series){
    window.location.href = "products.php?brand=" + encodeURIComponent(currentBrand) + "&series=" + encodeURIComponent(series);
}

function goToMideaType(type){
    window.location.href = "products.php?brand=" + encodeURIComponent(currentBrand) + "&midea_type=" + encodeURIComponent(type);
}

function goToCarrier(){
    window.location.href = "products.php?brand=Carrier";
}
</script>

</body>
</html>