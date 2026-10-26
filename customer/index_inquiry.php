<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Air Conditioner Units</title>
    <link rel="icon" type="image/png" href="favicon.png">

<link rel="stylesheet" href="styles.css">
<link rel="stylesheet" href="css/navbar.css">

</head>

<body>

<!-- NAVBAR -->
<nav class="navbar">

    <!-- LOGO -->
    <div class="logo">
        <img src="GSB homepage Image/GSB Logo.png" alt="GSB Logo">
    </div>


    <!-- RIGHT SIDE -->
    <div class="nav-right">

        <!-- MAIN LINKS -->
        <div class="nav-links">
            <a href="index.php">Home</a>
            <a href="products.php">Products</a>
            <a href="services.php">Services</a>
            <a href="about.php">About Us</a>
        </div>


        <!-- PROFILE -->
        <div class="profile-container-nav">

            <a href="profile.php">
                <img 
                    src="images/users.png" 
                    alt="Profile"
                    class="profile-pic"
                >
            </a>

        </div>


        <!-- HAMBURGER -->
        <div class="menu-container">

            <div class="menu-icon" id="menuIcon">
                <span></span>
                <span></span>
                <span></span>
            </div>

            <div class="dropdown-menu" id="dropdownMenu">
                <a href="profile.php">Profile Info</a>
                <a href="my_orders.php">My Order</a>
                <a href="order_history.php">Order History</a>
                <a href="logout.php">Logout</a>
            </div>

        </div>

    </div>

</nav>


<!-- HEADER -->
<header class="brand-header">

    <h1>Air Conditioner Units</h1>

    <p>
        Find the perfect cooling solution for your space.
    </p>

</header>


<!-- SEARCH + SORT -->
<div class="top-controls">

    <div class="search-bar">

        <input
            type="text"
            id="searchInput"
            placeholder="Search products..."
        >

        <button onclick="fetchProducts()">
            Search
        </button>

    </div>


    <div class="sort-box">

        <label for="sortSelect">
            Sort:
        </label>

        <select id="sortSelect">

            <option value="">
                Default
            </option>

            <option value="name_asc">
                A-Z
            </option>

            <option value="price_asc">
                Low Price
            </option>

            <option value="price_desc">
                High Price
            </option>

        </select>

    </div>

</div>


<!-- FILTER BUTTON -->
<div class="filter-toggle-container">

    <button
        id="filterBtn"
        class="filter-toggle-btn">

        FILTER PRODUCTS

    </button>

</div>


<!-- FILTER PANEL -->
<div
    id="filterPanel"
    class="filter-panel">

    <div class="filter-section">

        <label>
            SERIES
        </label>

        <select id="filterSeries">

            <option value="">
                All Series
            </option>

            <option value="Window Series">
                Window Series
            </option>

            <option value="Portable Series">
                Portable Series
            </option>

            <option value="Pinnacle AG">
                Pinnacle AG
            </option>

            <option value="Airstill Premium">
                Airstill Premium
            </option>

            <option value="MID Series">
                MID Series
            </option>

            <option value="Cooling King Series Premium">
                Cooling King Series Premium
            </option>

            <option value="D-Smart Series">
                D-Smart Series
            </option>

            <option value="D-Smart Queen Series">
                D-Smart Queen Series
            </option>

            <option value="D-Smart King Series">
                D-Smart King Series
            </option>

        </select>

    </div>


    <div class="filter-section">

        <label>
            HORSEPOWER
        </label>

        <select id="filterHP">

            <option value="">
                All HP
            </option>

            <option value="0.5">
                0.5 HP
            </option>

            <option value="0.75">
                0.75 HP
            </option>

            <option value="0.8">
                0.8 HP
            </option>

            <option value="1.0">
                1.0 HP
            </option>

            <option value="1.5">
                1.5 HP
            </option>

            <option value="2.0">
                2.0 HP
            </option>

            <option value="2.5">
                2.5 HP
            </option>

            <option value="3.0">
                3.0 HP
            </option>

        </select>

    </div>


    <div class="filter-section">

        <label>
            TYPE
        </label>

        <select id="filterInverter">

            <option value="">
                All Types
            </option>

            <option value="1">
                Non-Inverter
            </option>

            <option value="0">
                Inverter
            </option>

        </select>

    </div>


    <div class="filter-section">

        <label>
            AVAILABILITY
        </label>

        <select id="filterStock">

            <option value="">
                All Availability
            </option>

            <option value="Available">
                Available
            </option>

            <option value="Limited Stock">
                Limited Stock
            </option>


        </select>

    </div>


    <button
        class="apply-filter-btn"
        onclick="fetchProducts()">

        Apply Filters

    </button>


    <button
        class="clear-filter-btn"
        onclick="clearFilters()">

        Clear Filters

    </button>

</div>


<!-- PRODUCTS -->
<main
    id="productGrid"
    class="product-grid">
</main>


<script>

const isLoggedIn =
<?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;


/* =========================
   PAGE LOAD
========================= */

document.addEventListener(
    'DOMContentLoaded',
    () => {

        const urlParams =
            new URLSearchParams(window.location.search);

        const mideaTypeFromURL =
            urlParams.get("midea_type");

        const seriesFromURL =
            urlParams.get("series");


        if (seriesFromURL) {

            document.getElementById(
                "filterSeries"
            ).value = seriesFromURL;

        }


        /*
         * IMPORTANT:
         * midea_type=Window remains active
         * when this page is opened from:
         *
         * index_inquiry.php?midea_type=Window
         */

        fetchProducts();


        document
            .getElementById("searchInput")
            .addEventListener(
                "keypress",
                function(e) {

                    if (e.key === "Enter") {
                        fetchProducts();
                    }

                }
            );


        document
            .getElementById("sortSelect")
            .addEventListener(
                "change",
                fetchProducts
            );


        document
            .getElementById("filterSeries")
            .addEventListener(
                "change",
                fetchProducts
            );


        document
            .getElementById("filterHP")
            .addEventListener(
                "change",
                fetchProducts
            );


        document
            .getElementById("filterInverter")
            .addEventListener(
                "change",
                fetchProducts
            );


        document
            .getElementById("filterStock")
            .addEventListener(
                "change",
                fetchProducts
            );


        document
            .getElementById("filterBtn")
            .addEventListener(
                "click",
                function() {

                    document
                        .getElementById("filterPanel")
                        .classList.toggle("active");

                }
            );

    }
);


/* =========================
   IMAGE PATH
========================= */

function getProductImage(product) {

    let image =
        product.image
        ? String(product.image).trim()
        : "";

    if (image === "") {

        return "images/no-image.png";

    }


    let brand =
        product.brand
        ? String(product.brand)
            .toLowerCase()
            .trim()
        : "";


    let model =
        product.IndoorUnitModel
        ? String(product.IndoorUnitModel).trim()
        : "";


    const gsbProductPics = [

        "FP-51ARA006HMNV-N5",
        "MWF1-07CMNS-NCO",
        "MWF1-09CMNS-NCO",
        "MWF2-12CMN8-NB9",
        "MWTF2-18CMN1-NCO",
        "MWTF1-21CMN1-NCO",
        "MWF1-09CRN8-NCO",
        "MWF2-12CRN8-NB9",
        "MWF2-18CRN8-NC1",
        "FP-51ARA010HEIV-N4",
        "FP-51ARA015HEIV-N4"

    ];


    if (gsbProductPics.includes(model)) {

        return "images/products/Gsb_productpics/" + image;

    }


    if (brand === "midea") {

        return "images/products/midea/" + image;

    }


    if (brand === "daikin") {

        return "images/products/daikin/" + image;

    }


    if (brand === "carrier") {

        return "images/products/carrier/" + image;

    }


    return "images/no-image.png";

}


/* =========================
   FETCH PRODUCTS
========================= */

async function fetchProducts() {

    const search =
        document.getElementById(
            "searchInput"
        ).value.trim();


    const series =
        document.getElementById(
            "filterSeries"
        ).value;


    const hp =
        document.getElementById(
            "filterHP"
        ).value;


    const inverter =
        document.getElementById(
            "filterInverter"
        ).value;


    const stock =
        document.getElementById(
            "filterStock"
        ).value;


    const sort =
        document.getElementById(
            "sortSelect"
        ).value;


    const urlParams =
        new URLSearchParams(
            window.location.search
        );


    const mideaTypeFromURL =
        urlParams.get("midea_type");


    const params =
        new URLSearchParams();


    if (search) {

        params.append(
            "search",
            search
        );

    }


    if (series) {

        params.append(
            "series",
            series
        );

    }


    if (hp) {

        params.append(
            "hp",
            hp
        );

    }


    if (inverter !== "") {

        params.append(
            "is_non_inverter",
            inverter
        );

    }


    if (stock) {

        params.append(
            "stock_status",
            stock
        );

    }


    if (sort) {

        params.append(
            "sort",
            sort
        );

    }


    /*
     * Keep the URL filter.
     *
     * Example:
     * index_inquiry.php?midea_type=Window
     */

    if (mideaTypeFromURL) {

        params.append(
            "midea_type",
            mideaTypeFromURL
        );

    }


    const productGrid =
        document.getElementById(
            "productGrid"
        );


    productGrid.innerHTML =
        "<p class='loading'>Loading products...</p>";


    try {

        const response =
            await fetch(
                "fetch_products.php?" +
                params.toString()
            );


        const data =
            await response.json();


        renderProducts(
            data.Products
        );


    } catch (error) {

        console.error(
            "Product loading error:",
            error
        );


        productGrid.innerHTML =
            "<p class='error-message'>Error loading products.</p>";

    }

}


/* =========================
   RENDER PRODUCTS
========================= */

function renderProducts(products) {

    const productGrid =
        document.getElementById(
            "productGrid"
        );


    productGrid.innerHTML = "";


    if (
        !products ||
        products.length === 0
    ) {

        productGrid.innerHTML =
            "<p class='no-products'>No products found.</p>";

        return;

    }


    products.forEach(
        p => {

            const price =
                new Intl.NumberFormat(
                    "en-PH",
                    {
                        style: "currency",
                        currency: "PHP"
                    }
                ).format(
                    parseFloat(
                        p.SRP_PHP || 0
                    )
                );


            const stockStatus =
                p.stock_status ||
                "Available";


            const stockClass =
                stockStatus
                    .toLowerCase()
                    .replaceAll(
                        " ",
                        "-"
                    );


            const card = `

                <div class="product-card">

                    <div class="product-image-box">

                        <img
                            src="${getProductImage(p)}"
                            alt="${p.IndoorUnitModel || 'Air Conditioner'}"
                            onerror="this.onerror=null; this.src='images/no-image.png';"
                        >

                    </div>


                    <h3>
                        ${p.SeriesName || "No Series"}
                    </h3>


                    <h4>
                        ${p.CapacityHP || "0"} HP
                    </h4>


                    <p class="product-model">
                        ${p.IndoorUnitModel || ""}
                    </p>


                    ${
                        p.OutdoorUnitModel
                        ? `
                        <p class="outdoor-model">
                            ${p.OutdoorUnitModel}
                        </p>
                        `
                        : ""
                    }


                    <p class="product-type">

                        ${
                            p.is_non_inverter == 1
                            ? "Non-Inverter"
                            : "Inverter"
                        }

                    </p>


                    <p class="product-price">
                        ${price}
                    </p>


                    <span class="stock-badge ${stockClass}">

                        ${stockStatus}

                    </span>

                </div>

            `;


            productGrid.innerHTML += card;

        }
    );

}


/* =========================
   INQUIRE
========================= */

function inquireProduct(product) {

    if (!isLoggedIn) {

        alert(
            "Please login first."
        );

        window.location.href =
            "login.php";

        return;

    }


    window.location.href =
        "inquiry.php?product=" +
        encodeURIComponent(product);

}


/* =========================
   CLEAR FILTERS
========================= */

function clearFilters() {

    document.getElementById(
        "searchInput"
    ).value = "";


    document.getElementById(
        "filterSeries"
    ).value = "";


    document.getElementById(
        "filterHP"
    ).value = "";


    document.getElementById(
        "filterInverter"
    ).value = "";


    document.getElementById(
        "filterStock"
    ).value = "";


    document.getElementById(
        "sortSelect"
    ).value = "";


    fetchProducts();

}


/* =========================
   MENU
========================= */

function toggleMenu() {

    const menu =
        document.getElementById(
            "dropdownMenu"
        );


    const icon =
        document.getElementById(
            "menuIcon"
        );


    menu.classList.toggle(
        "show"
    );


    icon.classList.toggle(
        "active"
    );

}

</script>

</body>
</html>