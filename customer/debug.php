<?php
// TEMPORARY DEBUG PAGE — DELETE BEFORE GOING LIVE
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo '<h2>PHP Version: ' . phpversion() . '</h2>';

// Check config.php exists
$config_path = __DIR__ . '/../config.php';
echo '<h3>Config file path: ' . $config_path . '</h3>';
echo '<p>Config exists: ' . (file_exists($config_path) ? '<b style="color:green">YES</b>' : '<b style="color:red">NO</b>') . '</p>';

if (file_exists($config_path)) {
    require_once $config_path;
    echo '<p>DB_HOST: ' . DB_HOST . '</p>';
    echo '<p>DB_PORT: ' . DB_PORT . '</p>';
    echo '<p>DB_USER: ' . DB_USER . '</p>';
    echo '<p>DB_NAME: ' . DB_NAME . '</p>';
    echo '<p>APP_ENV: ' . APP_ENV . '</p>';

    // Test DB connection
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    if ($conn->connect_errno) {
        echo '<p style="color:red"><b>DB ERROR:</b> ' . htmlspecialchars($conn->connect_error) . '</p>';
    } else {
        echo '<p style="color:green"><b>DB Connected successfully!</b></p>';
        $conn->close();
    }
} else {
    echo '<p style="color:red">config.php missing — entrypoint script may have failed</p>';
    echo '<h3>Environment variables available:</h3><pre>';
    $vars = ['DB_HOST','DB_PORT','DB_USER','DB_PASS','DB_NAME','APP_ENV','APP_BASE_URL'];
    foreach ($vars as $v) {
        echo $v . ' = ' . (getenv($v) ? htmlspecialchars(getenv($v)) : '(not set)') . "\n";
    }
    echo '</pre>';
}

// Check includes directory
echo '<h3>Files in /var/www/html:</h3><pre>';
foreach (scandir(__DIR__ . '/..') as $f) {
    echo $f . "\n";
}
echo '</pre>';
