<?php
// Global error handler to catch all errors
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Custom error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $msg = "[" . date('Y-m-d H:i:s') . "] Error [$errno]: $errstr in $errfile on line $errline\n";
    error_log($msg, 3, __DIR__ . '/error.log');
    
    if (ini_get('display_errors')) {
        echo "<div style='background: #ffebee; border: 1px solid #c62828; padding: 15px; margin: 10px; font-family: monospace;'>";
        echo "<strong>Error:</strong> $errstr<br>";
        echo "<strong>File:</strong> $errfile<br>";
        echo "<strong>Line:</strong> $errline<br>";
        echo "</div>";
    }
    
    return false;
});

// Custom exception handler
set_exception_handler(function($exception) {
    $msg = "[" . date('Y-m-d H:i:s') . "] Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine() . "\n";
    $msg .= "Stack trace:\n" . $exception->getTraceAsString() . "\n";
    error_log($msg, 3, __DIR__ . '/error.log');
    
    if (ini_get('display_errors')) {
        echo "<div style='background: #ffebee; border: 1px solid #c62828; padding: 15px; margin: 10px; font-family: monospace;'>";
        echo "<h2>Exception Caught:</h2>";
        echo "<strong>Message:</strong> " . htmlspecialchars($exception->getMessage()) . "<br>";
        echo "<strong>File:</strong> " . htmlspecialchars($exception->getFile()) . "<br>";
        echo "<strong>Line:</strong> " . $exception->getLine() . "<br>";
        echo "<strong>Stack Trace:</strong><pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
        echo "</div>";
    } else {
        http_response_code(500);
        echo "Internal Server Error. Please check the error log for details.";
    }
});

// Shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $msg = "[" . date('Y-m-d H:i:s') . "] Fatal Error [{$error['type']}]: {$error['message']} in {$error['file']} on line {$error['line']}\n";
        error_log($msg, 3, __DIR__ . '/error.log');
        
        if (ini_get('display_errors')) {
            echo "<div style='background: #ffebee; border: 1px solid #c62828; padding: 15px; margin: 10px; font-family: monospace;'>";
            echo "<h2>Fatal Error:</h2>";
            echo "<strong>Message:</strong> " . htmlspecialchars($error['message']) . "<br>";
            echo "<strong>File:</strong> " . htmlspecialchars($error['file']) . "<br>";
            echo "<strong>Line:</strong> " . $error['line'] . "<br>";
            echo "</div>";
        }
    }
});
