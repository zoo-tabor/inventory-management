<?php
/**
 * Diagnostic Page
 * Check PHP configuration and environment
 */

echo "<h1>PHP Diagnostics</h1>";

echo "<h2>PHP Version</h2>";
echo phpversion();

echo "<h2>Current Directory</h2>";
echo getcwd();

echo "<h2>Files in Directory</h2>";
echo "<pre>";
print_r(scandir(__DIR__));
echo "</pre>";

echo "<h2>.env File Exists?</h2>";
echo file_exists(__DIR__ . '/.env') ? 'YES' : 'NO';

echo "<h2>Config Directory Exists?</h2>";
echo file_exists(__DIR__ . '/config') ? 'YES' : 'NO';

echo "<h2>Loaded Extensions</h2>";
echo "<pre>";
print_r(get_loaded_extensions());
echo "</pre>";

echo "<h2>Server Variables</h2>";
echo "<pre>";
print_r($_SERVER);
echo "</pre>";
