<?php
/**
 * This file is a legacy stub. Status updates are handled by assign_technician.php.
 * Kept to prevent 404 errors from any old bookmarks/links.
 */
session_start();
require_once '../includes/db_connect.php';
require_once __DIR__ . '/../../includes/auth_helpers.php';

require_admin_session('../admin_login.php');

header('Location: ../admin_inquiries.php');
exit();
