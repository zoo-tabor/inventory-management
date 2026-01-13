<?php
/**
 * Simple Test File
 * Test if PHP is working
 */

echo "<h1>✅ PHP is Working!</h1>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Current Directory: " . getcwd() . "</p>";

echo "<h2>Environment Check:</h2>";
echo "<ul>";
echo "<li>.env exists: " . (file_exists(__DIR__ . '/.env') ? 'YES' : 'NO') . "</li>";
echo "<li>config/ exists: " . (file_exists(__DIR__ . '/config') ? 'YES' : 'NO') . "</li>";
echo "<li>index.php exists: " . (file_exists(__DIR__ . '/index.php') ? 'YES' : 'NO') . "</li>";
echo "</ul>";

echo "<h2>Quick Links:</h2>";
echo "<ul>";
echo '<li><a href="/debug.php?key=debug_2026_sk">Debug Page</a></li>';
echo '<li><a href="/phpinfo.php">PHP Info (Diagnostics)</a></li>';
echo '<li><a href="/install/migrate.php?key=sk_mig_2026_officeo_secure_key_xj8k2p">Migrations</a></li>';
echo '<li><a href="/index.php">Try Index.php</a></li>';
echo '<li><a href="/">Try Root</a></li>';
echo "</ul>";

echo "<h2>Try Loading Config:</h2>";
try {
    require_once __DIR__ . '/config/config.php';
    echo "<p style='color: green;'>✅ Config loaded successfully!</p>";
    echo "<p>APP_NAME: " . (defined('APP_NAME') ? APP_NAME : 'NOT DEFINED') . "</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Config failed: " . $e->getMessage() . "</p>";
}

echo "<h2>Try Loading Database:</h2>";
try {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/classes/Database.php';
    $db = Database::getInstance();
    echo "<p style='color: green;'>✅ Database connected!</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database failed: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
