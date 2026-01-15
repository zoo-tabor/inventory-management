<?php
/**
 * Debug wrapper for by-item report
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require __DIR__ . '/by-item.php';
} catch (Throwable $e) {
    echo "<h1>Error in by-item.php</h1>";
    echo "<pre>";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString();
    echo "</pre>";
}
