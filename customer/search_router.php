<?php
// search_router.php

// 1. Grab the raw search text
$raw_search = $_GET['search'] ?? '';
$search_query = urlencode($raw_search); // Make it safe for URLs
$search_lower = strtolower($raw_search); // Convert to lowercase for easy matching

// 2. Define keywords that mean the user is looking for a Service
// You can add more keywords to this list as needed!
$service_keywords = [
    'repair', 
    'clean', 
    'cleaning',
    'install', 
    'installation',
    'maintenance', 
    'check', 
    'check up',
    'service'
];

// 3. Check if the user's search contains any of the service keywords
$is_service = false;
foreach ($service_keywords as $keyword) {
    // If we find a match inside their search text...
    if (strpos($search_lower, $keyword) !== false) {
        $is_service = true;
        break; // Stop checking, we know it's a service!
    }
}

// 4. Automatically route them to the correct page
if ($is_service) {
    // Redirect to the services page
    header("Location: book_service.php?search=" . $search_query);
    exit;
} else {
    // Redirect to the products page
    header("Location: products.php?search=" . $search_query);
    exit;
}
?>