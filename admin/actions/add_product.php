<?php
/**
 * LEGACY STUB — add_product.php
 *
 * This was an early prototype page with no authentication and an unsafe file upload.
 * All product management is now handled through admin_products.php.
 *
 * This stub exists only to avoid 404 errors from old bookmarks.
 */
session_start();
require_once '../includes/db_connect.php';
require_once __DIR__ . '/../../includes/auth_helpers.php';

require_admin_session('../admin_login.php');

header('Location: ../admin_products.php');
exit();
