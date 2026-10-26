<?php
/**
 * LEGACY STUB — edit_availability.php
 *
 * Slot editing is now handled entirely through the AJAX modal in
 * admin/availability_schedule.php. This standalone form page is no longer
 * used by any part of the current UI.
 *
 * This stub exists only to avoid 404 errors from old bookmarks.
 */
session_start();
require_once '../includes/db_connect.php';
require_once __DIR__ . '/../../includes/auth_helpers.php';

require_admin_session('../admin_login.php');

header('Location: ../availability_schedule.php');
exit();
