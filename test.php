<?php
// Test error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>PHP Version: " . PHP_VERSION . "</h1>";
echo "<h2>Required for this project: PHP 7.4+</h2>";

if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    echo "<p style='color: red;'>ERROR: This application requires PHP 7.4 or higher. Current version: " . PHP_VERSION . "</p>";
} else {
    echo "<p style='color: green;'>OK: PHP version is compatible.</p>";
}

echo "<hr>";

// Test loading the libraries
try {
    require_once __DIR__ . '/config.php';
    echo "<p>config.php loaded successfully</p>";
    
    require_once __DIR__ . '/lib/DataManager.php';
    echo "<p>DataManager.php loaded successfully</p>";
    
    require_once __DIR__ . '/lib/helpers.php';
    echo "<p>helpers.php loaded successfully</p>";
    
    echo "<p style='color: green;'><strong>All core files loaded successfully!</strong></p>";
    echo "<p>Try accessing <a href='/'>index.php</a></p>";
    
} catch (Throwable $e) {
    echo "<p style='color: red;'><strong>Error loading files:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
