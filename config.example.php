<?php
/**
 * GSB Airconditioning Services — Configuration Template
 *
 * Copy this file to config.php and fill in your actual values.
 * NEVER commit config.php — it contains real credentials.
 */

define('DB_HOST', 'your-db-host');
define('DB_PORT', 3306);
define('DB_USER', 'your-db-user');
define('DB_PASS', 'your-db-password');
define('DB_NAME', 'gsb_aircon_system');

define('APP_BASE_URL', 'https://your-app-url.com');
define('APP_ENV', 'production');   // 'development' | 'production'

define('SESSION_LIFETIME', 7200);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900);

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-email@gmail.com');
define('SMTP_PASS', 'your-gmail-app-password');
