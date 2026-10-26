<?php
/**
 * cart.php — legacy stub
 *
 * The active cart UI is a slide-in panel inside products.php (powered by
 * get_cart_items.php and add_to_cart.php via AJAX).
 *
 * This file is kept to avoid 404 errors from old bookmarks.
 * It simply redirects to the products page where the cart panel lives.
 */
session_start();
header("Location: products.php");
exit();
